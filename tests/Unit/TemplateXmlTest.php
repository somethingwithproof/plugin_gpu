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

use Somethingwithproof\Gpu\MetricModel;
use Somethingwithproof\Gpu\TemplateXml;

function tpl_root(): string {
	return dirname(__DIR__, 2);
}

it('ships exactly the XML the generator produces', function () {
	expect((string) file_get_contents(tpl_root() . '/resource/script_server/' . TemplateXml::DATA_QUERY_FILE))->toBe(TemplateXml::dataQuery())
		->and((string) file_get_contents(tpl_root() . '/templates/' . TemplateXml::TEMPLATE_FILE))->toBe(TemplateXml::templates());
});

it('defines a script server data query keyed by GPU identity, with every call the input supports', function () {
	$query = simplexml_load_string(TemplateXml::dataQuery());

	expect((string) $query->script_function)->toBe('ss_gpu')
		->and((string) $query->script_path)->toBe('|path_cacti|/plugins/gpu/ss_gpu.php')
		->and((string) $query->arg_prepend)->toBe('|host_id|')
		->and((string) $query->arg_index)->toBe('index')
		->and((string) $query->arg_num_indexes)->toBe('num_indexes')
		->and((string) $query->arg_query)->toBe('query')
		->and((string) $query->arg_get)->toBe('get')
		->and((string) $query->fields->gpuKey->query_name)->toBe('index')
		// Cacti re-maps data sources by the sort field at every reindex, so
		// a positional field there would move history between cards.
		->and((string) $query->index_order)->toBe('gpuKey');

	// Cacti splits each query line at the first delimiter, so the index
	// (vendor/UUID) must never contain it.
	expect(str_contains('nvidia/GPU-00000000-0000-4000-8000-000000000001', (string) $query->output_delimiter))->toBeFalse()
		->and((string) $query->output_delimiter)->toBe('!');

	$outputs = [];

	foreach ($query->fields->children() as $name => $field) {
		if ((string) $field->direction === 'output') {
			$outputs[] = $name;
			expect((string) $field->query_name)->toBe($name);
		}
	}

	expect($outputs)->toBe(MetricModel::names());
});

it('uses valid Cacti 1.2 hashes, each defined once', function () {
	$xml = TemplateXml::templates();

	preg_match_all('/<(hash_[0-9a-z]+)>/', $xml, $opened);

	foreach ($opened[1] as $hash) {
		expect($hash)->toMatch('/^hash_[0-9]{2}0103[0-9a-f]{32}$/');
	}

	expect($opened[1])->toBe(array_values(array_unique($opened[1])));
	expect(simplexml_load_string($xml))->not->toBeFalse();
});

it('maps every model field in every data query graph, so one data source serves all of a GPU\'s graphs', function () {
	$xml = simplexml_load_string(TemplateXml::templates());
	$dq  = $xml->{TemplateXml::hash('04', TemplateXml::DATA_QUERY)};

	expect((string) $dq->xml_path)->toBe('<path_cacti>/resource/script_server/plugin_gpu.xml')
		->and((string) $dq->data_input_id)->toBe('hash_030103332111d8b54ac8ce939af87a7eac0c06')
		->and(count($dq->graphs->children()))->toBe(count(TemplateXml::graphs()));

	foreach ($dq->graphs->children() as $graph) {
		$fields = [];

		foreach ($graph->rrd->children() as $item) {
			$fields[] = (string) $item->snmp_field_name;
			expect((string) $item->data_template_rrd_id)->toBe(TemplateXml::hash('08', (string) $item->snmp_field_name));
		}

		expect($fields)->toBe(MetricModel::names())
			->and((string) $graph->sv_graph->children()[0]->text)->toContain('|query_gpuIndex| |query_gpuName|');
	}
});

it('draws only model fields, with no CDEF that could turn unknown into zero', function () {
	$xml   = simplexml_load_string(TemplateXml::templates());
	$names = MetricModel::names();

	foreach (TemplateXml::graphs() as $name => $graph) {
		$template = $xml->{TemplateXml::hash('00', $name)};

		expect((string) $template->graph->title)->toBe(TemplateXml::title($name))
			->and((string) $template->graph->t_title)->toBe('on');

		foreach ($template->items->children() as $hash => $item) {
			expect((string) $item->cdef_id)->toBe('0');

			// Each input lists its items with the export's 00 type code.
			$member = 'hash_00' . substr($hash, 7);
			$found  = false;

			foreach ($template->inputs->children() as $input) {
				$found = $found || in_array($member, explode('|', (string) $input->items), true);
			}

			expect($found)->toBeTrue($hash);
		}

		foreach ($graph['items'] as [$metric]) {
			expect($names)->toContain($metric);
		}
	}

	$covered = array_merge(...array_map(fn ($g) => array_column($g['items'], 0), array_values(TemplateXml::graphs())));

	// Cacti stores only the fields some graph draws, so all must be drawn.
	expect($covered)->toEqualCanonicalizing(MetricModel::names());
});

it('defines one gauge per model field in the data template, with the model\'s range', function () {
	$xml      = simplexml_load_string(TemplateXml::templates());
	$template = $xml->{TemplateXml::hash('01', TemplateXml::DATA_TEMPLATE)};

	expect(count($template->items->children()))->toBe(count(MetricModel::all()));

	foreach (MetricModel::all() as $metric) {
		$item = $template->items->{TemplateXml::hash('08', $metric->name)};

		expect((string) $item->data_source_name)->toBe($metric->name)
			->and((string) $item->data_source_type_id)->toBe('1')
			->and((string) $item->rrd_minimum)->toBe($metric->rrdMin())
			->and((string) $item->rrd_maximum)->toBe($metric->rrdMax());
	}

	$host = $xml->{TemplateXml::hash('02', TemplateXml::DEVICE_TEMPLATE)};

	expect((string) $host->data_queries)->toBe(TemplateXml::hash('04', TemplateXml::DATA_QUERY))
		->and((string) $host->class)->toBe('hpc');
});

it('does not count the templates as installed while Cacti\'s copy of the data query XML is missing or stale', function () {
	$cacti = sys_get_temp_dir() . '/gpu-tpl-' . bin2hex(random_bytes(4));
	mkdir($cacti . '/resource/script_server', 0o700, true);

	// Every database object exists.
	$GLOBALS['__gpu_db_rows']['SELECT id FROM'] = [['id' => 7]];

	$installer = new \Somethingwithproof\Gpu\TemplateInstaller(new \Somethingwithproof\Gpu\CactiObjectRepository(false), new \Somethingwithproof\Gpu\LockRepository(false), tpl_root(), $cacti);
	$target    = $installer->dataQueryTarget();
	$missing   = $installer->installed();

	file_put_contents($target, 'stale');
	$stale = $installer->installed();

	file_put_contents($target, (string) file_get_contents(tpl_root() . '/resource/script_server/' . TemplateXml::DATA_QUERY_FILE));
	$current = $installer->installed();

	unlink($target);
	rmdir($cacti . '/resource/script_server');
	rmdir($cacti . '/resource');
	rmdir($cacti);
	unset($GLOBALS['__gpu_db_rows']['SELECT id FROM']);

	expect($missing)->toBeFalse()
		->and($stale)->toBeFalse()
		->and($current)->toBeTrue();
});
