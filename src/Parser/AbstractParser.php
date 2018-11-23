<?php

namespace ApolloRIP\Logchecker\Parser;

use ApolloRIP\Logchecker\Drives;

abstract class AbstractParser {
	protected $log_path;
	protected $log;
	protected $logs;

	protected $parsed = false;

	protected $combined_log = false;
	protected $program = '';
	protected $version = '';
	protected $version_date = '';
	protected $tracks = [];

	protected $score = 100;
	protected $checksum = true;
	protected $details = [];

	var $secure_mode = true;
	var $non_secure_mode = null;

	protected $drive_found = false;
	protected $offsets = [];


	public function __construct($log_path, $log) {
		$this->log_path = $log_path;
		$this->log = $log;
	}

	public function parse($validate_checksum) {
		if ($this->parsed) {
			return;
		}
		$this->preParse();
		$this->splitLogs();
		$this->combined_log = count($this->logs) > 1;
		if ($this->combined_log) {
			$this->addDetail('Combined Logs (' . count($this->logs) . ')', 0, true);
		}
		for ($i = 0; $i < count($this->logs); $i++) {
			$log =& $this->logs[$i];
			$this->parseRipDetails($i);

			$log = preg_replace_callback("/Used drive( +): (.+)/i", array($this, 'parseDrive'), $log, 1, $count);
			if (!$count) {
				$this->addDetail('Could not verify used drive', 1);
			}

			// $this->getTrackDetails()
			if ($validate_checksum && $this->checksum) {
				$this->validateChecksum($i);
			}
		}
	}

	protected function preParse() {}
	abstract protected function splitLogs();
	abstract protected function parseRipDetails($log_index);
	abstract protected function validateChecksum($log_index);

	protected function parseDrive($matches) {
		$fake_drives = array(
			'Generic DVD-ROM SCSI CdRom Device'
		);
		if (in_array(trim($matches[2]), $fake_drives)) {
			$this->addDetail('Virtual drive used: ' . $matches[2], 20);
			return "<span class=\"log5\">Used Drive$matches[1]</span>: <span class=\"bad\">$matches[2]</span>";
		}
		$drive_name = $matches[2];
		$drive_name = str_replace('JLMS', 'Lite-ON', $drive_name);
		$drive_name = str_replace('HL-DT-ST', 'LG Electronics', $drive_name);
		$drive_name = str_replace(array('Matshita', 'MATSHITA'), 'Panasonic', $drive_name);
		$drive_name = str_replace(array('TSSTcorpBD', 'TSSTcorpCD', 'TSSTcorpDVD'), array('TSSTcorp BD', 'TSSTcorp CD', 'TSSTcorp DVD'), $drive_name);
		$drive_name = preg_replace('/\s+-\s/', ' ', $drive_name);
		$drive_name = preg_replace('/\s+/', ' ', $drive_name);
		$drive_name = preg_replace('/\(revision [a-zA-Z0-9\.\,\-]*\)/', '', $drive_name);
		$drive_name = preg_replace('/ Adapter.*$/', '', $drive_name);
		$search = array_filter(preg_split('/[^0-9a-z]/i', trim($drive_name)), function($elem) { return strlen($elem) > 0; });
		$search_text = implode("%' AND Name LIKE '%", $search);

		$drives = Drives::getDrives($search_text);
		// We add both the offsets as they come from the DB, as well as them stripped of their
		// leading sign, as some rippers like to strip that sign too.
		$this->offsets = array_unique($drives);
		foreach ($this->offsets as $key => $offset) {
			$StrippedOffset  = preg_replace('/[^0-9]/s', '', $offset);
			$this->offsets[] = $StrippedOffset;
		}
		reset($this->offsets);
		if (count($drives)) {
			$class = 'good';
			$this->drive_found = true;
		}
		else {
			$class = 'badish';
			$matches[2] .= ' (not found in database)';
		}
		return "<span class=\"log5\">Used Drive$matches[1]</span>: <span class=\"$class\">$matches[2]</span>";
	}

	protected function addDetail($message, $subtract = 0, $notice = false) {
		$subtract = intval($subtract);
		$prepend = ($notice) ? '[Notice] ' : '';
		$append = ($subtract !== 0) ? ' ('.(($subtract < 0) ? '+' : '-') . abs($subtract) . ' point'. ((abs($subtract) === 1) ? '' : 's') . ')' : '';
		$this->details[] = $prepend . $message . $append;
		$this->score -= $subtract;
	}

	public function getProgram() {
		return $this->program;
	}

	public function getScore() {
		return $this->score;
	}

	public function getDetails() {
		return $this->details;
	}

	public function hasValidChecksum() {
		return $this->checksum;
	}
}