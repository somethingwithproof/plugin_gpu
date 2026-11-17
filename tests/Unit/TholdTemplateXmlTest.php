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

use Somethingwithproof\Gpu\Metric;
use Somethingwithproof\Gpu\MetricModel;
use Somethingwithproof\Gpu\TemplateXml;
use Somethingwithproof\Gpu\TholdTemplateXml;

// The thold_template columns of Thold v1.8.2 other than id
// (includes/database.php at 1eb406bd); its import refuses a template with
// any other column.
const THOLD_182_COLUMNS = ['hash', 'name', 'suggested_name', 'data_template_id', 'data_template_hash', 'data_template_name', 'data_source_id', 'data_source_name', 'data_source_friendly', 'thold_hi', 'thold_low', 'thold_fail_trigger', 'time_hi', 'time_low', 'time_fail_trigger', 'time_fail_length', 'thold_warning_hi', 'thold_warning_low', 'thold_warning_fail_trigger', 'thold_warning_fail_count', 'time_warning_hi', 'time_warning_low', 'time_warning_fail_trigger', 'time_warning_fail_length', 'thold_enabled', 'thold_type', 'bl_ref_time_range', 'bl_type', 'bl_pct_down', 'bl_pct_up', 'bl_fail_trigger', 'bl_fail_count', 'bl_alert', 'bl_cf', 'repeat_alert', 'notify_extra', 'notify_warning_extra', 'notify_templated', 'notify_warning', 'notify_alert', 'snmp_event_category', 'snmp_event_description', 'snmp_event_severity', 'snmp_event_warning_severity', 'data_type', 'show_units', 'units_suffix', 'decimals', 'cdef', 'percent_ds', 'expression', 'upper_ds', 'exempt', 'thold_hrule_alert', 'thold_hrule_warning', 'skipscale', 'restored_alert', 'reset_ack', 'persist_ack', 'email_subject', 'email_subject_warn', 'email_subject_restoral', 'email_body', 'email_body_warn', 'email_body_restoral', 'trigger_cmd_high', 'trigger_cmd_low', 'trigger_cmd_norm', 'syslog_priority', 'syslog_facility', 'syslog_enabled', 'notes', 'external_id', 'format_file', 'graph_timespan'];

it('ships exactly the Thold templates the generator produces', function () {
	expect((string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . TholdTemplateXml::FILE))->toBe(TholdTemplateXml::xml());
});

it('writes only columns Thold 1.8.2 imports, and points at the GPU data template', function () {
	$xml = simplexml_load_string(TholdTemplateXml::xml());

	expect($xml)->not->toBeFalse()
		->and(count($xml->children()))->toBe(count(TholdTemplateXml::templates()));

	foreach ($xml->children() as $template) {
		foreach ($template->children() as $column) {
			expect(THOLD_182_COLUMNS)->toContain($column->getName());
		}

		expect((string) $template->data_template_id)->toBe(substr(TemplateXml::hash('01', TemplateXml::DATA_TEMPLATE), -32))
			->and((string) $template->data_source_id)->toBe(substr(TemplateXml::hash('08', (string) $template->data_source_name), -32))
			->and((string) $template->hash)->toMatch('/^[0-9a-f]{32}$/');
	}
});

it('thresholds only drawn gauges, never a counter, Idle, or the volatile ECC totals', function () {
	$drawn = [];

	foreach (TemplateXml::graphs() as $graph) {
		foreach ($graph['items'] as $item) {
			$drawn[] = $item[0];
		}
	}

	foreach (TholdTemplateXml::templates() as $name => [$item, $high, $trigger]) {
		$metric = MetricModel::find($item);

		expect($metric)->not->toBeNull()
			->and(in_array($metric->kind, [Metric::GAUGE, Metric::INDICATOR], true))->toBeTrue()
			->and($drawn)->toContain($item)
			->and(is_numeric($high))->toBeTrue()
			->and($trigger)->toBeGreaterThanOrEqual(1);

	}

	$items = array_column(TholdTemplateXml::templates(), 0);

	expect($items)->not->toContain('ecc_corr')
		->and($items)->not->toContain('ecc_uncorr')
		->and($items)->not->toContain('thr_sw_power_cap')
		->and($items)->toContain('age')
		->and($items)->toContain('ecc_uncorr_new')
		->and($items)->toContain('thr_hw_thermal');
});

it('uses an exact Thold threshold on the derived power percentage', function () {
	$xml      = simplexml_load_string(TholdTemplateXml::xml());
	$power    = null;

	foreach ($xml->children() as $template) {
		if ((string) $template->name === 'GPU - Power Above Limit') {
			$power = $template;
			break;
		}
	}

	expect($power)->not->toBeNull()
		->and((string) $power->data_source_name)->toBe('power_pct')
		->and((string) $power->data_type)->toBe('0')
		->and((string) $power->percent_ds)->toBe('')
		->and((string) $power->expression)->toBe('');
});

it('stores every data source as a gauge, so Thold never computes a rate from an unknown value', function () {
	$xml = TemplateXml::templates();

	// data_source_type_id 1 is GAUGE; 2 COUNTER, 3 DERIVE, 4 ABSOLUTE.
	preg_match_all('/<data_source_type_id>(\d+)<\/data_source_type_id>/', $xml, $types);

	expect($types[1])->not->toBe([])
		->and(array_unique($types[1]))->toBe(['1']);
});
