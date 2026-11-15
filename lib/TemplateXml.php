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

namespace Somethingwithproof\Gpu;

/**
 * Generates the Cacti objects the plugin ships (REQ-TPL-001): the GPU data
 * query's resource XML, and one template export holding the data template,
 * the graph templates, the data query, and the GPU Node device template.
 *
 * The files under resource/ and templates/ are this class's output, and a
 * unit test fails when they differ, so the shipped XML is reviewable and
 * never drifts from the metric model. Hashes are derived from fixed names,
 * so every release produces the same hash for the same object and a
 * re-import updates it in place instead of adding a copy.
 *
 * Core objects are referenced by their own hashes and are not exported:
 * import_xml_data() loads every existing hash before it resolves references.
 */
final class TemplateXml {
	public const DATA_QUERY_FILE = 'plugin_gpu.xml';
	public const TEMPLATE_FILE   = 'gpu_templates.xml';

	// The Cacti 1.2 export format version code.
	private const VERSION = '0103';

	// Core's "Get Script Server Data (Indexed)" method and its fields.
	private const SCRIPT_SERVER_DIM = 'hash_030103332111d8b54ac8ce939af87a7eac0c06';
	private const DIM_FIELDS        = [
		'hash_070103172b4b0eacee4948c6479f587b62e512', // index_type
		'hash_07010330fb5d5bcf3d66bb5abe88596f357c26', // index_value
		'hash_07010331112c85ae4ff821d3b288336288818c', // output_type
	];

	// Core's "Normal" GPRINT preset and "5 Minute Collection" profile.
	// import_xml_data() replaces the profile with the one it is given.
	private const GPRINT  = 'hash_060103e9c43831e54eca8069317a2ce8c6f751';
	private const PROFILE = 'hash_200103d62c52891f4f9688729a5bc9fad91b18';

	private const LINE1 = 4;
	private const LINE2 = 5;
	private const AREA  = 7;
	private const GPRINT_ITEM = 9;

	private const CF_AVERAGE = 1;
	private const CF_MAX     = 3;
	private const CF_LAST    = 4;

	public const DATA_TEMPLATE   = 'GPU - Metrics';
	public const DATA_QUERY      = 'GPU - Devices';
	public const DEVICE_TEMPLATE = 'GPU Node';

	// Identity fields the data query lists for each GPU.
	public const INPUT_FIELDS = [
		'gpuKey'   => ['index', 'Identity key'],
		'gpuIndex' => ['gpu_index', 'GPU index'],
		'gpuName'  => ['name', 'Product name'],
		'gpuBusId' => ['pci_bus_id', 'PCI bus ID'],
	];

	/**
	 * The graph templates (REQ-GRAPH-001). Each item draws one model field;
	 * none uses a vendor-only field (REQ-GRAPH-004). Unknown values are
	 * drawn as gaps because no item applies a CDEF (REQ-GRAPH-002).
	 *
	 * @return array<string, array{label: string, base: int, upper: string, items: list<array{0: string, 1: int, 2: string, 3: string}>}>
	 */
	public static function graphs(): array {
		return [
			'GPU - Utilization' => ['label' => 'percent', 'base' => 1000, 'upper' => '100', 'items' => [
				['util_gpu', self::LINE2, '76B900', 'GPU'],
				['util_mem', self::LINE1, '0072C6', 'Memory controller'],
			]],
			'GPU - Memory' => ['label' => 'bytes', 'base' => 1024, 'upper' => '0', 'items' => [
				['mem_total', self::AREA, 'C9DDF0', 'Total'],
				['mem_used', self::AREA, '0072C6', 'Used'],
			]],
			'GPU - Temperature' => ['label' => 'degrees C', 'base' => 1000, 'upper' => '0', 'items' => [
				['temp_gpu', self::LINE2, 'D9541E', 'GPU'],
			]],
			'GPU - Power' => ['label' => 'watts', 'base' => 1000, 'upper' => '0', 'items' => [
				['power', self::AREA, 'F2B705', 'Draw'],
				['power_limit', self::LINE1, 'B00020', 'Limit'],
			]],
			'GPU - Clocks' => ['label' => 'MHz', 'base' => 1000, 'upper' => '0', 'items' => [
				['clock_sm', self::LINE2, '76B900', 'SM'],
				['clock_mem', self::LINE1, '7A3E9D', 'Memory'],
			]],
		];
	}

	/**
	 * A Cacti hash: type code, version code, and the md5 of a fixed name.
	 */
	public static function hash(string $type, string $name): string {
		return 'hash_' . $type . self::VERSION . md5('plugin_gpu:' . $type . ':' . $name);
	}

	public static function title(string $graph): string {
		return '|host_description| - GPU |query_gpuIndex| |query_gpuName| - ' . substr($graph, strlen('GPU - '));
	}

	public static function dataQuery(): string {
		$fields = [];

		foreach (self::INPUT_FIELDS as $field => [$query, $name]) {
			$fields[] = self::element($field, ['name' => $name, 'direction' => 'input', 'query_name' => $query], 2);
		}

		foreach (MetricModel::all() as $metric) {
			$fields[] = self::element($metric->name, ['name' => $metric->label, 'direction' => 'output', 'query_name' => $metric->name], 2);
		}

		return "<interface>\n" . self::lines([
			'name'               => 'GPU devices',
			'description'        => 'GPUs from the GPU plugin cache, one index per GPU identity',
			'script_path'        => '|path_cacti|/plugins/gpu/ss_gpu.php',
			'script_function'    => 'ss_gpu',
			'script_server'      => 'php',
			'arg_prepend'        => '|host_id|',
			'arg_index'          => 'index',
			'arg_num_indexes'    => 'num_indexes',
			'arg_query'          => 'query',
			'arg_get'            => 'get',
			// The index is vendor/UUID, which never contains this character.
			'output_delimiter'   => '!',
			'index_order'        => 'gpuIndex:gpuKey',
			'index_order_type'   => 'numeric',
			'index_title_format' => '|chosen_order_field|',
		], 1) . "\t<fields>\n" . implode('', $fields) . "\t</fields>\n</interface>\n";
	}

	public static function templates(): string {
		$dataTemplate = self::hash('01', self::DATA_TEMPLATE);
		$dataQuery    = self::hash('04', self::DATA_QUERY);
		$out          = "<cacti>\n";

		$out .= self::element(self::hash('02', self::DEVICE_TEMPLATE), [
			'name'            => self::DEVICE_TEMPLATE,
			'class'           => 'hpc',
			'graph_templates' => '',
			'data_queries'    => $dataQuery,
		], 1);

		foreach (self::graphs() as $name => $graph) {
			$out .= self::graphTemplate($name, $graph, $dataTemplate);
		}

		$out .= self::dataTemplate($dataTemplate);
		$out .= self::queryExport($dataQuery, $dataTemplate);

		return $out . "</cacti>\n";
	}

	/**
	 * @param array{label: string, base: int, upper: string, items: list<array{0: string, 1: int, 2: string, 3: string}>} $graph
	 */
	private static function graphTemplate(string $name, array $graph, string $dataTemplate): string {
		$settings = [
			'title' => self::title($name), 'vertical_label' => $graph['label'], 'image_format_id' => '3', 'height' => '200', 'width' => '700',
			'base_value' => (string) $graph['base'], 'slope_mode' => 'on', 'auto_scale' => 'on', 'auto_scale_opts' => '2', 'auto_scale_log' => '',
			'scale_log_units' => '', 'auto_scale_rigid' => '', 'upper_limit' => $graph['upper'], 'lower_limit' => '0', 'unit_value' => '',
			'unit_exponent_value' => '', 'unit_length' => '', 'no_gridfit' => '', 'alt_y_grid' => '', 'right_axis' => '', 'right_axis_label' => '',
			'right_axis_format' => '0', 'right_axis_formatter' => '0', 'left_axis_formatter' => '0', 'auto_padding' => 'on', 'dynamic_labels' => '',
			'force_rules_legend' => '', 'tab_width' => '', 'legend_position' => '0', 'legend_direction' => '0',
		];

		$graphXml = '';

		foreach ($settings as $field => $value) {
			$graphXml .= self::line('t_' . $field, $field === 'title' ? 'on' : '', 3) . self::line($field, $value, 3);
		}

		$items    = '';
		$inputs   = '';
		$sequence = 0;

		foreach ($graph['items'] as [$metric, $type, $color, $text]) {
			$members = [];
			$task    = self::hash('08', $metric);

			$draws = [
				[$type, $color, self::CF_AVERAGE, $text, ''],
				[self::GPRINT_ITEM, '', self::CF_LAST, 'Current:', ''],
				[self::GPRINT_ITEM, '', self::CF_AVERAGE, 'Average:', ''],
				[self::GPRINT_ITEM, '', self::CF_MAX, 'Maximum:', 'on'],
			];

			foreach ($draws as [$itemType, $itemColor, $cf, $itemText, $return]) {
				$sequence++;

				$hash      = self::hash('10', $name . ':' . $sequence);
				$members[] = 'hash_00' . substr($hash, 7);

				$items .= self::element($hash, [
					'graph_type_id' => (string) $itemType, 'task_item_id' => $task, 'color_id' => $itemColor === '' ? '0' : $itemColor, 'alpha' => 'FF',
					'consolidation_function_id' => (string) $cf, 'cdef_id' => '0', 'vdef_id' => '0', 'shift' => '', 'value' => '', 'gprint_id' => self::GPRINT,
					'textalign' => '', 'text_format' => $itemText, 'hard_return' => $return, 'line_width' => $itemType === self::LINE2 ? '2.00' : '1.00',
					'dashes' => '', 'dash_offset' => '0', 'sequence' => (string) $sequence,
				], 3);
			}

			$inputs .= self::element(self::hash('09', $name . ':' . $metric), [
				'name' => 'Data Source [' . $metric . ']', 'description' => '', 'column_name' => 'task_item_id', 'items' => implode('|', $members),
			], 3);
		}

		return "\t<" . self::hash('00', $name) . ">\n" . self::lines(['name' => $name, 'multiple' => '', 'test_source' => ''], 2)
			. "\t\t<graph>\n" . $graphXml . "\t\t</graph>\n"
			. "\t\t<items>\n" . $items . "\t\t</items>\n"
			. "\t\t<inputs>\n" . $inputs . "\t\t</inputs>\n"
			. "\t</" . self::hash('00', $name) . ">\n";
	}

	private static function dataTemplate(string $hash): string {
		$ds = self::lines([
			't_name' => '', 'name' => '|host_description| - GPU |query_gpuIndex| |query_gpuName|', 'data_source_path' => '',
			'data_input_id' => self::SCRIPT_SERVER_DIM, 't_data_source_profile_id' => '', 'data_source_profile_id' => self::PROFILE,
			't_rrd_step' => '', 'rrd_step' => '300', 't_active' => '', 'active' => 'on',
		], 3);

		$items = '';

		foreach (MetricModel::all() as $metric) {
			$items .= self::element(self::hash('08', $metric->name), [
				't_data_source_name' => '', 'data_source_name' => $metric->name, 't_rrd_minimum' => '', 'rrd_minimum' => $metric->rrdMin(),
				't_rrd_maximum' => '', 'rrd_maximum' => $metric->rrdMax(), 't_data_source_type_id' => '', 'data_source_type_id' => '1',
				't_rrd_heartbeat' => '', 'rrd_heartbeat' => '600', 't_data_input_field_id' => '', 'data_input_field_id' => '0',
			], 3);
		}

		$data = '';

		foreach (self::DIM_FIELDS as $i => $field) {
			$data .= self::element(sprintf('item_%03d', $i), ['data_input_field_id' => $field, 't_value' => 'on', 'value' => ''], 3);
		}

		return "\t<$hash>\n" . self::line('name', self::DATA_TEMPLATE, 2)
			. "\t\t<ds>\n" . $ds . "\t\t</ds>\n"
			. "\t\t<items>\n" . $items . "\t\t</items>\n"
			. "\t\t<data>\n" . $data . "\t\t</data>\n"
			. "\t</$hash>\n";
	}

	private static function queryExport(string $hash, string $dataTemplate): string {
		$graphs = '';

		// Every graph maps every field, so whichever graph creates a GPU's
		// data source first, the data source polls all of them and the other
		// graphs reuse it: one RRD file per GPU.
		foreach (array_keys(self::graphs()) as $name) {
			$rrd = '';

			foreach (MetricModel::all() as $i => $metric) {
				$rrd .= self::element(sprintf('item_%03d', $i), [
					'snmp_field_name' => $metric->name, 'data_template_id' => $dataTemplate, 'data_template_rrd_id' => self::hash('08', $metric->name),
				], 5);
			}

			$graphHash = self::hash('11', $name);

			$graphs .= "\t\t\t<$graphHash>\n" . self::lines(['graph_template_id' => self::hash('00', $name), 'name' => $name], 4)
				. "\t\t\t\t<rrd>\n" . $rrd . "\t\t\t\t</rrd>\n"
				. "\t\t\t\t<sv_graph>\n" . self::element(self::hash('12', $name . ':title'), ['field_name' => 'title', 'sequence' => '1', 'text' => self::title($name)], 5) . "\t\t\t\t</sv_graph>\n"
				. "\t\t\t\t<sv_data_source>\n" . self::element(self::hash('13', $name . ':name'), ['field_name' => 'name', 'data_template_id' => $dataTemplate, 'sequence' => '1', 'text' => '|host_description| - GPU |query_gpuIndex| |query_gpuName|'], 5) . "\t\t\t\t</sv_data_source>\n"
				. "\t\t\t</$graphHash>\n";
		}

		return "\t<$hash>\n" . self::lines([
			'name'          => self::DATA_QUERY,
			'description'   => 'GPUs reported by the GPU plugin, one index per GPU identity',
			'xml_path'      => '<path_cacti>/resource/script_server/' . self::DATA_QUERY_FILE,
			'data_input_id' => self::SCRIPT_SERVER_DIM,
		], 2) . "\t\t<graphs>\n" . $graphs . "\t\t</graphs>\n\t</$hash>\n";
	}

	/**
	 * @param array<string, string> $children
	 */
	private static function element(string $name, array $children, int $depth): string {
		$tabs = str_repeat("\t", $depth);

		return "$tabs<$name>\n" . self::lines($children, $depth + 1) . "$tabs</$name>\n";
	}

	/**
	 * @param array<string, string> $children
	 */
	private static function lines(array $children, int $depth): string {
		$out = '';

		foreach ($children as $name => $value) {
			$out .= self::line($name, $value, $depth);
		}

		return $out;
	}

	private static function line(string $name, string $value, int $depth): string {
		return str_repeat("\t", $depth) . "<$name>" . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</$name>\n";
	}
}
