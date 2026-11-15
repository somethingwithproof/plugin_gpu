<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_gpu, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_gpu
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

/*
 * Source rules from docs/PLAN.md "Code structure" and ADR 0006 that a parser
 * can check. The PHP 8.0 parse of the entry points themselves is proven by the
 * CI lint job on a real PHP 8.0; these checks catch the same mistakes earlier
 * and cover the rules a lint cannot.
 */

function gpu_root(): string {
	return dirname(__DIR__, 2);
}

/**
 * @return list<string> plugin PHP files outside tests/, relative to the root
 */
function gpu_sources(): array {
	$files = [];
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(gpu_root(), FilesystemIterator::SKIP_DOTS));

	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen(gpu_root()) + 1);

		if ($file->getExtension() === 'php' && !str_starts_with($relative, 'tests/') && !str_starts_with($relative, '.git/')) {
			$files[] = $relative;
		}
	}

	sort($files);

	return $files;
}

/**
 * @return list<string> files Cacti may include before the PHP version check
 */
function gpu_entry_points(): array {
	return array_values(array_filter(gpu_sources(), fn (string $f) => !str_starts_with($f, 'lib/')));
}

it('keeps every PHP source file within the gettext scan depth', function () {
	foreach (gpu_sources() as $file) {
		expect(substr_count($file, '/'))->toBeLessThanOrEqual(1, $file);
	}
});

it('keeps entry points free of PHP 8.1 and later syntax', function () {
	$banned = [T_ENUM => 'enum', T_READONLY => 'readonly'];

	foreach (gpu_entry_points() as $file) {
		$tokens = PhpToken::tokenize((string) file_get_contents(gpu_root() . '/' . $file));

		foreach ($tokens as $i => $token) {
			expect(isset($banned[$token->id]))->toBeFalse("$file:{$token->line} uses {$token->text}");

			// "never" as a return type and "new" in an initializer are 8.1.
			if ($token->is(T_STRING) && strtolower($token->text) === 'never') {
				$previous = $tokens[$i - 1]->is(T_WHITESPACE) ? $tokens[$i - 2] : $tokens[$i - 1];
				expect($previous->text)->not->toBe(':', "$file:{$token->line} uses never");
			}
		}
	}
});

it('uses no PHP 8.3 or later features anywhere', function () {
	foreach (gpu_sources() as $file) {
		$source = (string) file_get_contents(gpu_root() . '/' . $file);
		$tokens = PhpToken::tokenize($source);

		expect($source)->not->toContain('json_validate(')
			->and($source)->not->toContain('#[\\Override]')
			->and($source)->not->toContain('#[Override]');

		foreach ($tokens as $i => $token) {
			if (!$token->is(T_CONST)) {
				continue;
			}

			// A typed constant has two identifiers between const and "=".
			$names = 0;

			for ($j = $i + 1; $j < count($tokens) && $tokens[$j]->text !== '='; $j++) {
				$names += $tokens[$j]->is(T_STRING) ? 1 : 0;
			}

			expect($names)->toBe(1, "$file:{$token->line} declares a typed constant");
		}
	}
});

it('defines exactly one class, interface, or enum per lib file, named after the file', function () {
	foreach (glob(gpu_root() . '/lib/*.php') as $path) {
		$tokens = PhpToken::tokenize((string) file_get_contents($path));
		$names  = [];

		foreach ($tokens as $i => $token) {
			if ($token->is([T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT]) && !$tokens[$i - 1]->is(T_DOUBLE_COLON)) {
				$next = $tokens[$i + 1]->is(T_WHITESPACE) ? $tokens[$i + 2] : $tokens[$i + 1];

				if ($next->is(T_STRING)) {
					$names[] = $next->text;
				}
			}
		}

		expect($names)->toBe([basename($path, '.php')], basename($path))
			->and((string) file_get_contents($path))->toContain("namespace Somethingwithproof\\Gpu;");
	}
});

it('keeps lib classes away from request variables and globals', function () {
	foreach (glob(gpu_root() . '/lib/*.php') as $path) {
		$source = (string) file_get_contents($path);

		expect($source)->not->toMatch('/\$_(REQUEST|GET|POST|SESSION|SERVER|COOKIE)\b/', basename($path))
			->and($source)->not->toMatch('/\bglobal\s+\$/', basename($path))
			->and($source)->not->toContain('$GLOBALS', basename($path));
	}
});

it('issues SQL only from repository and schema classes, through prepared helpers', function () {
	foreach (gpu_sources() as $file) {
		$source = (string) file_get_contents(gpu_root() . '/' . $file);

		expect($source)->not->toMatch('/\bdb_(execute|fetch_cell|fetch_row|fetch_assoc)\s*\(/', $file);

		// include/unbooted-uninstall.php is the one exception: it runs where
		// lib/ cannot load (see its header).
		$repository = str_starts_with($file, 'lib/') && preg_match('/^(Schema|\w+Repository)\.php$/', basename($file)) === 1;

		if (!$repository && $file !== 'include/unbooted-uninstall.php') {
			expect($source)->not->toMatch('/\bdb_\w+_prepared\s*\(/', $file);
		}
	}
});

it('passes the gpu text domain to every translated plugin string', function () {
	// Labels intentionally reused from core keep the core domain.
	$core = ['Management'];

	foreach (gpu_sources() as $file) {
		$tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents(gpu_root() . '/' . $file)), fn ($t) => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));

		foreach ($tokens as $i => $token) {
			if (!$token->is(T_STRING) || $token->text !== '__' || ($tokens[$i + 1]->text ?? '') !== '(' || ($tokens[$i - 1]->is(T_FUNCTION))) {
				continue;
			}

			$depth = 0;
			$last  = null;

			for ($j = $i + 1; $j < count($tokens); $j++) {
				$depth += $tokens[$j]->text === '(' ? 1 : ($tokens[$j]->text === ')' ? -1 : 0);

				if ($depth === 0) {
					break;
				}

				if ($depth === 1 && $tokens[$j]->text !== ',' && $tokens[$j]->text !== '(') {
					$last = $tokens[$j];
				}
			}

			$first = $tokens[$i + 2];

			if ($first->is(T_CONSTANT_ENCAPSED_STRING) && in_array(trim($first->text, '\'"'), $core, true)) {
				continue;
			}

			expect($last?->text)->toBe("'gpu'", "$file:{$token->line}");
		}
	}
});

it('ships a translation template containing every plugin string', function () {
	$pot = (string) file_get_contents(gpu_root() . '/locales/po/cacti.pot');

	foreach (['The GPU plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.'] as $msgid) {
		expect($pot)->toContain('msgid "' . $msgid . '"');
	}
});

it('formats translated strings with __() itself, never sprintf(__())', function () {
	// Core's __() hands the text domain to sprintf(), so a placeholder in
	// sprintf(__("%d", "gpu"), $n) takes "gpu" and prints 0 or the
	// domain. The values go inside the call: __("%d", $n, "gpu").
	foreach (gpu_sources() as $file) {
		expect((string) file_get_contents(gpu_root() . '/' . $file))->not->toContain('sprintf(__(', $file);
	}
});

it('uses plain placeholders in translated strings, because core\'s __() does not format positional ones', function () {
	// __() only formats a string that has %d, %s, and the like. A string with
	// only %1$d placeholders comes back raw.
	foreach (gpu_sources() as $file) {
		$source = (string) file_get_contents(gpu_root() . '/' . $file);

		preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", $source, $texts);

		foreach ($texts[1] as $text) {
			expect($text)->not->toMatch('/%[0-9]+\$/', $file);
		}
	}
});

it('logs through cacti_log() under the GPU tag only', function () {
	foreach (gpu_sources() as $file) {
		$code = '';

		foreach (PhpToken::tokenize((string) file_get_contents(gpu_root() . '/' . $file)) as $token) {
			$code .= $token->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $token->text;
		}

		preg_match_all('/\bcacti_log\s*\((.*?)\);/s', $code, $calls);

		foreach ($calls[1] as $arguments) {
			expect(str_contains($arguments, "'GPU'"))->toBeTrue($file);
		}
	}
});

it('ships no Composer runtime dependencies', function () {
	$manifest = gpu_root() . '/composer.json';

	if (is_file($manifest)) {
		$require = json_decode((string) file_get_contents($manifest), true)['require'] ?? [];

		foreach (array_keys($require) as $package) {
			expect($package === 'php' || str_starts_with($package, 'ext-'))->toBeTrue($package);
		}
	}

	expect(is_dir(gpu_root() . '/vendor'))->toBeFalse();
});

it('reads request variables only through Cacti\'s accessors', function () {
	// REQ-UI-002. $_SERVER is allowed for the request method alone.
	foreach (gpu_sources() as $file) {
		$source = (string) file_get_contents(gpu_root() . '/' . $file);

		expect($source)->not->toMatch('/\$_(REQUEST|GET|POST|COOKIE|FILES)\b/', $file);

		preg_match_all('/\$_SERVER\[\'([A-Z_]+)\'\]/', $source, $keys);

		expect(array_values(array_diff($keys[1], ['REQUEST_METHOD'])))->toBe([], $file);
	}
});

it('runs no command on a GPU node, and starts one process: the collector', function () {
	// REQ-LIFE-005 and ADR 0008. The plugin reads GPU nodes over SNMP only;
	// the one program it starts is its own collector, from poller_bottom,
	// with an escaped argument.
	$banned  = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'exec_background', 'exec_into_array', 'proc_nice'];
	$allowed = [];

	foreach (gpu_sources() as $file) {
		$tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents(gpu_root() . '/' . $file)), fn ($t) => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));

		foreach ($tokens as $i => $token) {
			expect($token->text)->not->toBe('`', "$file:{$token->line} uses a backtick command");

			if ($token->is(T_STRING) && in_array(strtolower($token->text), $banned, true) && ($tokens[$i + 1]->text ?? '') === '(' && !($tokens[$i - 1]->is([T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION]))) {
				$allowed[] = "$file:" . $token->text . '(' . ($tokens[$i + 2]->text ?? '') . ', ' . ($tokens[$i + 4]->text ?? '') . ')';
			}
		}
	}

	expect($allowed)->toBe(['setup.php:exec_background($php, $args)']);

	$setup = (string) file_get_contents(gpu_root() . '/setup.php');

	expect($setup)->toContain("\$args = '-q ' . cacti_escapeshellarg(__DIR__ . '/poller_gpu.php');");
});

it('names no state-changing vendor command anywhere', function () {
	// REQ-LIFE-005: clocks, power limits, persistence mode, resets, MIG
	// layout, and amd-smi set or reset are never invoked. A string that
	// pairs a vendor tool with one of these is refused wherever it appears.
	$mutating = '/\b(nvidia-smi|dcgmi|amd-smi|rocm-smi)\b.*(\s-(pm|pl|ac|rac|lgc|rgc|lmc|rmc|r|e|c|mig|cgi|cci|dgi|dci)\b|--(persistence-mode|power-limit|applications-clocks|reset-applications-clocks|lock-gpu-clocks|reset-gpu-clocks|lock-memory-clocks|reset-memory-clocks|gpu-reset|ecc-config|compute-mode|multi-instance-gpu)\b|\s(mig|set|reset|config)\s)/i';

	foreach (gpu_sources() as $file) {
		foreach (PhpToken::tokenize((string) file_get_contents(gpu_root() . '/' . $file)) as $token) {
			if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
				expect($token->text)->not->toMatch($mutating, "$file:{$token->line}");
			}
		}
	}

	expect(preg_match($mutating, "'nvidia-smi -pl 200'"))->toBe(1)
		->and(preg_match($mutating, "'nvidia-smi --gpu-reset -i 0'"))->toBe(1)
		->and(preg_match($mutating, "'amd-smi set --power-cap 100'"))->toBe(1)
		->and(preg_match($mutating, "'nvidia-smi --query-gpu=uuid,index --format=csv'"))->toBe(0);
});
