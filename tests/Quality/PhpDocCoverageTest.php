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

/**
 * AST-level PHPDoc coverage gate enforced via token_get_all().
 *
 * Verifies every production class, interface, enum, trait, method, and named
 * function carries a docblock with @param tags for all parameters, @return
 * tags for all non-constructor/non-void return values, and @throws tags
 * whenever the body throws an exception directly.
 *
 * @return list<string> absolute paths of production PHP files
 */
function gpu_phpdoc_production_files(): array {
	$root  = dirname(__DIR__, 2);
	$files = array_merge(
		glob($root . '/lib/*.php') ?: [],
		glob($root . '/*.php') ?: [],
		glob($root . '/include/*.php') ?: [],
		glob($root . '/cli/*.php') ?: [],
	);

	$files = array_values(array_filter(
		$files,
		static fn (string $path): bool => !in_array(substr($path, strlen($root) + 1), ['index.php', 'include/autoload.php'], true),
	));

	sort($files);

	return $files;
}

/**
 * Inspect one production PHP file using token_get_all() and return all PHPDoc
 * violations.
 *
 * @param string $path     absolute file path
 * @param string $relative repository-relative path for diagnostics
 *
 * @return list<string>
 */
function gpu_phpdoc_audit_file(string $path, string $relative): array {
	$source = (string) file_get_contents($path);
	$tokens = token_get_all($source);
	$count  = count($tokens);
	$issues = [];

	for ($i = 0; $i < $count; $i++) {
		if (!is_array($tokens[$i])) {
			continue;
		}

		$id = $tokens[$i][0];

		if (!in_array($id, [T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT, T_FUNCTION], true)) {
			continue;
		}

		// Skip Foo::class and anonymous classes (new class ...).
		if ($id === T_CLASS) {
			$prev = $i - 1;

			while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
				$prev--;
			}

			if ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_DOUBLE_COLON, T_NEW], true)) {
				continue;
			}
		}

		// Skip closures (function (...) without a T_STRING identifier).
		$next = $i + 1;

		while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
			$next++;
		}

		if ($next < $count && $tokens[$next] === '&') {
			$next++;

			while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
				$next++;
			}
		}

		if ($next >= $count || !is_array($tokens[$next]) || $tokens[$next][0] !== T_STRING) {
			continue;
		}

		$name = $tokens[$next][1];
		$line = $tokens[$i][2];

		// Walk backwards across modifiers, whitespace, and PHP 8 attributes to find T_DOC_COMMENT.
		$doc = null;
		$p   = $i - 1;

		while ($p >= 0) {
			if (is_array($tokens[$p])) {
				$tid = $tokens[$p][0];

				if (in_array($tid, [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY], true)) {
					$p--;

					continue;
				}

				if ($tid === T_DOC_COMMENT) {
					$doc = $tokens[$p][1];
				}

				break;
			}

			if ($tokens[$p] === ']') {
				$depth = 1;
				$p--;

				while ($p >= 0 && $depth > 0) {
					if ($tokens[$p] === ']') {
						$depth++;
					} elseif ($tokens[$p] === '[' || (is_array($tokens[$p]) && $tokens[$p][0] === T_ATTRIBUTE)) {
						$depth--;
					}
					$p--;
				}

				continue;
			}

			break;
		}

		if ($doc === null) {
			$issues[] = sprintf('%s:%d %s is missing a PHPDoc block', $relative, $line, $name);

			continue;
		}

		if ($id !== T_FUNCTION) {
			continue;
		}

		// Extract parameter names from the signature (...).
		$sig = $next + 1;

		while ($sig < $count && $tokens[$sig] !== '(') {
			$sig++;
		}

		$params = [];
		$depth  = 0;
		$sigEnd = $sig;

		for ($j = $sig; $j < $count; $j++) {
			if ($tokens[$j] === '(') {
				$depth++;
			} elseif ($tokens[$j] === ')') {
				$depth--;

				if ($depth === 0) {
					$sigEnd = $j;

					break;
				}
			} elseif ($depth === 1 && is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
				$params[] = $tokens[$j][1];
			}
		}

		foreach ($params as $param) {
			if (!preg_match('/@param\s+\S[^\n]*' . preg_quote($param, '/') . '\b/', $doc)) {
				$issues[] = sprintf('%s:%d %s() is missing @param for %s', $relative, $line, $name, $param);
			}
		}

		// Extract return type (if declared) between ')' and '{' or ';'.
		$returnType = '';
		$bodyStart  = -1;

		for ($j = $sigEnd + 1; $j < $count; $j++) {
			if ($tokens[$j] === '{' || $tokens[$j] === ';') {
				$bodyStart = $tokens[$j] === '{' ? $j : -1;

				break;
			}

			$returnType .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
		}

		$cleanReturn = strtolower(trim(ltrim($returnType, ':')));

		// Scan direct function body (excluding nested closures/anonymous classes) for throw and return value.
		$throwsDirectly   = false;
		$returnsValue     = false;

		if ($bodyStart !== -1) {
			$braceDepth  = 0;
			$nestedDepth = null;

			for ($j = $bodyStart; $j < $count; $j++) {
				$tok = $tokens[$j];

				if ($tok === '{' || (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
					$braceDepth++;

					continue;
				}

				if ($tok === '}') {
					$braceDepth--;

					if ($nestedDepth !== null && $braceDepth < $nestedDepth) {
						$nestedDepth = null;
					}

					if ($braceDepth === 0) {
						break;
					}

					continue;
				}

				if ($nestedDepth !== null) {
					continue;
				}

				if (is_array($tok) && in_array($tok[0], [T_FUNCTION, T_FN], true)) {
					if ($tok[0] === T_FUNCTION) {
						$nestedDepth = $braceDepth + 1;
					}

					continue;
				}

				if (is_array($tok) && $tok[0] === T_THROW) {
					$throwsDirectly = true;
				}

				if (is_array($tok) && $tok[0] === T_RETURN) {
					$k = $j + 1;

					while ($k < $count && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
						$k++;
					}

					if ($k < $count && $tokens[$k] !== ';') {
						$returnsValue = true;
					}
				}
			}
		}

		$needsReturn = $name !== '__construct'
			&& $cleanReturn !== 'void'
			&& $cleanReturn !== 'never'
			&& ($cleanReturn !== '' || $returnsValue);

		if ($needsReturn && !str_contains($doc, '@return')) {
			$issues[] = sprintf('%s:%d %s() is missing @return', $relative, $line, $name);
		}

		if ($throwsDirectly && !str_contains($doc, '@throws')) {
			$issues[] = sprintf('%s:%d %s() throws an exception but is missing @throws', $relative, $line, $name);
		}
	}

	return $issues;
}

it('enforces 100% AST-verified PHPDoc coverage across all production PHP files', function () {
	$root   = dirname(__DIR__, 2);
	$files  = gpu_phpdoc_production_files();
	$issues = [];

	expect($files)->not->toBeEmpty();

	foreach ($files as $path) {
		$relative = substr($path, strlen($root) + 1);
		$issues   = array_merge($issues, gpu_phpdoc_audit_file($path, $relative));
	}

	expect($issues)->toBe([], implode("\n", $issues));
});
