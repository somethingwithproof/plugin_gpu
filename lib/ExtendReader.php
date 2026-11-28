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
 * Reads one extend entry from a node (ADR 0008). The plugin sends the agent
 * nothing but the read requests; the command is fixed in the node's
 * snmpd.conf.
 */
interface ExtendReader {
	/**
	 * @throws CollectionError when the agent cannot be read or has no entry
	 *                         by that name
	 * @param NodeDevice $device
	 * @param string $token
	 * @return ExtendOutput
	 */
	public function read(NodeDevice $device, string $token): ExtendOutput;
}
