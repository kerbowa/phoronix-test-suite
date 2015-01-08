<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2015, Phoronix Media
	Copyright (C) 2015, Michael Larabel

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

class stress_run implements pts_option_interface
{
	const doc_section = 'Testing';
	const doc_description = 'This option will run the passed tests/suites in the multi-process stress-testing mode. The stress-run mode will not produce a result file but is rather intended for running multiple test profiles concurrently to stress / burn-in the system. The number of tests to run concurrently can be toggled via the PTS_CONCURRENT_TEST_RUNS environment variable and by default is set to a value of 2.';

	public static function argument_checks()
	{
		return array(
		new pts_argument_check('VARIABLE_LENGTH', array('pts_types', 'identifier_to_object'), null)
		);
	}
	public static function run($to_run)
	{
		pts_test_run_manager::set_batch_mode(array(
			'UploadResults' => false,
			'SaveResults' => false,
			'PromptForTestDescription' => false,
			'RunAllTestCombinations' => false,
			'PromptSaveName' => false,
			'PromptForTestIdentifier' => false,
			'OpenBrowser' => false
			));

		$tests_to_run_concurrently = 2;

		if(($j = getenv('PTS_CONCURRENT_TEST_RUNS')) && is_numeric($j) && $j > 1)
		{
			$test_to_run_concurrently = $j;
		}

		$test_flags = pts_c::batch_mode;

		if(pts_test_run_manager::initial_checks($to_run, $test_flags, 'SHORT') == false)
		{
			return false;
		}

		if(count($to_run) < $tests_to_run_concurrently)
		{
			echo PHP_EOL . 'More tests must be specified in order to run ' . $tests_to_run_concurrently . ' tests concurrently.';
			return false;
		}

		$test_run_manager = new pts_test_run_manager($test_flags);

		// Load the tests to run
		if($test_run_manager->load_tests_to_run($to_run) == false)
		{
			return false;
		}

		// Run the actual tests
		//$test_run_manager->pre_execution_process();

		$continue_test_flag = true;
		pts_client::$display->test_run_process_start($test_run_manager);
		$test_run_manager->disable_dynamic_run_count();
		$tests_left_to_run = $test_run_manager->get_tests_to_run();

		$tests_pids_active = array();

		while(!empty($tests_left_to_run) || !empty($tests_pids_active))
		{
			if($continue_test_flag == false)
				break;

			$test_types_active = array();
			foreach($tests_pids_active as $pid => &$test_index)
			{
				$ret = pcntl_waitpid($pid, $status, WNOHANG | WUNTRACED);

				if($ret)
				{
					if(pcntl_wifexited($status) || !posix_getsid($pid))
					{
						unset($tests_pids_active[$pid]);
						continue;
					}
				}

				array_push($test_types_active, $test_run_manager->get_test_to_run($test_index)->test_profile->get_test_hardware_type());
			}

			if(!empty($tests_left_to_run) && count($tests_pids_active) < $tests_to_run_concurrently)
			{
				$test_to_run = -1;
				foreach($tests_left_to_run as $i => $test)
				{
					if(!in_array($test->test_profile->get_test_hardware_type(), $test_types_active))
					{
						$test_to_run = $test;
					}
				}
				if($test_to_run == -1)
				{
					$i = array_rand(array_keys($tests_left_to_run));
					$test_to_run = $tests_left_to_run[$i];
				}

				$pid = pcntl_fork();
				if($pid == -1)
				{
					echo 'Forking failure.';
				}
				else if($pid)
				{
					$tests_pids_active[$pid] = $test_to_run;
				}
				else
				{
					$continue_test_flag = $test_run_manager->process_test_run_request($test_to_run);
					return;
				}

				unset($tests_left_to_run[$i]);
			}

			sleep(1);
		}

		foreach($test_run_manager->get_tests_to_run() as &$run_request)
		{
			// Remove cache shares
			foreach(pts_file_io::glob($run_request->test_profile->get_install_dir() . 'cache-share-*.pt2so') as $cache_share_file)
			{
				unlink($cache_share_file);
			}
		}
	}
	public static function invalid_command($passed_args = null)
	{
		pts_tests::invalid_command_helper($passed_args);
	}
}

?>
