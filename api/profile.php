<?php

/**
 * Some functionality for generating and working with profiles. Per ecobee
 * documentation: The values supplied for any given 5-minute interval is the
 * value at the start of the interval and is not an average.
 *
 * @author Jon Ziebell
 */
class profile extends cora\api {

  public static $exposed = [
    'private' => [],
    'public' => []
  ];

  public static $cache = [
    'generate' => 604800 // 7 Days
  ];

  /**
   * Generate a profile for the specified thermostat.
   *
   * @param int $thermostat_id
   *
   * @return array
   */
  public function generate($thermostat_id) {
    set_time_limit(0);

    // Make sure the thermostat_id provided is one of yours since there's no
    // user_id security on the runtime_thermostat table.
    $thermostats = $this->api('thermostat', 'read_id');
    if (isset($thermostats[$thermostat_id]) === false) {
      throw new Exception('Invalid thermostat_id.', 10300);
    }

    /**
     * This is an interesting thing to fiddle with. Basically, the longer the
     * minimum sample duration, the better your score. For example, let's say
     * I set this to 10m and my 30° delta is -1°. If I increase the time to
     * 60m, I may find that my 30° delta decreases to -0.5°.
     *
     * Initially I thought something was wrong, but this makes logical sense.
     * If I'm only utilizing datasets where the system was completely off for
     * a longer period of time, then I can infer that the outdoor conditions
     * were favorable to allowing that to happen. Higher minimums most likely
     * only include sunny periods with low wind.
     *
     * For now this is set to 30m, which I feel is an appropriate requirement.
     * I am not factoring in any variables outside of temperature for now.
     * Note that 30m is a MINIMUM due to the event_runtime_thermostat_text_id logic that
     * will go back in time by 30m to account for sensor changes if the
     * calendar event changes.
     */
    $minimum_sample_duration = [
      'heat_1' => 300,
      'heat_2' => 300,
      'auxliary_heat_1' => 300,
      'auxliary_heat_2' => 300,
      'cool_1' => 300,
      'cool_2' => 300,
      'resist' => 1800
    ];

    /**
     * How long the system must be on/off for before starting a sample. Setting
     * this to 5 minutes will use the very first sample which is fine if you
     * assume the temperature in the sample is taken at the end of the 5m.
     */
    $minimum_off_for = 300;
    $minimum_on_for = 300;

    /**
     * Increasing this value will decrease the number of data points by
     * allowing for larger outdoor temperature swings in a single sample. For
     * example, a value of 1 will start a new sample if the temperature
     * changes by 1°, and a value of 5 will start a new sample if the
     * temperature changes by 5°.
     */
    $smoothing = 1;

    /**
     * Require this many individual samples in a delta for a specific outdoor
     * temperature. Increasing this basically cuts off the extremes where
     * there are fewer samples.
     */
    $required_samples = 2;

    /**
     * Require this many individual points before a valid temperature profile
     * can be returned.
     */
    $required_points = 5;

    /**
     * How far back to query for additional data. For example, when the
     * event_runtime_thermostat_text_id changes I pull data from 30m ago. If that data is
     * not available in the current runtime chunk, then it will fail. This
     * will make sure that data is always included.
     */
    $max_lookback = 1800; // 30 min

    /**
     * How far in the future to query for additional data. For example, if a
     * sample ends 20 minutes prior to an event change, I need to look ahead
     * to see if an event change is in the future. If so, I need to adjust for
     * that because the sensor averages will already be wrong.
     */
    $max_lookahead = 1800; // 30 min

    /**
     * Attempt to ignore the effects of solar heating by only looking at
     * samples when the sun is down.
     */
    $ignore_solar_heating = true;

    // Get some stuff
    $thermostat = $this->api('thermostat', 'get', $thermostat_id);

    // Figure out all the starting and ending times. Round begin/end to the
    // nearest 5 minutes to help with the looping later on.
    $end_timestamp = time();
    $begin_timestamp = strtotime('-1 year', $end_timestamp);

    // Round to 5 minute intervals.
    $begin_timestamp = floor($begin_timestamp / 300) * 300;
    $end_timestamp = floor($end_timestamp / 300) * 300;

    $group_thermostats = $this->api(
      'thermostat',
      'read',
      [
        'attributes' => [
          'thermostat_group_id' => $thermostat['thermostat_group_id'],
          'inactive' => 0
        ]
      ]
    );

    // Get all of the relevant data
    $thermostat_ids = [];
    foreach($group_thermostats as $thermostat) {
      $thermostat_ids[] = $thermostat['thermostat_id'];
    }

    /**
     * Get the largest possible chunk size given the number of thermostats I
     * have to select data for. This is necessary to prevent the script from
     * running out of memory. Also, as of PHP 7, structures have 6-7x of
     * memory overhead.
     */
    $memory_limit = 16; // mb
    $memory_per_thermostat_per_day = 0.6; // mb
    $days = (int) floor($memory_limit / ($memory_per_thermostat_per_day * count($thermostat_ids)));

    $chunk_size = $days * 86400;

    if($chunk_size === 0) {
      throw new Exception('Too many thermostats; cannot generate temperature profile.', 10301);
    }

    $current_timestamp = $begin_timestamp;
    $chunk_end_timestamp = 0;
    $five_minutes = 300;
    $thirty_minutes = 1800;
    $all_off_for = 0;
    $heat_1_on_for = 0;
    $heat_2_on_for = 0;
    $auxiliary_heat_1_on_for = 0;
    $auxiliary_heat_2_on_for = 0;
    $cool_1_on_for = 0;
    $cool_2_on_for = 0;
    $samples = [];
    $first_timestamp = null;
    $setpoints = [
      'heat' => [],
      'cool' => []
    ];
    $runtime_seconds = [
      'heat_1' => 0,
      'heat_2' => 0,
      'auxiliary_heat_1' => 0,
      'auxiliary_heat_2' => 0,
      'cool_1' => 0,
      'cool_2' => 0
    ];
    $degree_days_baseline = 65;
    $degree_days = [];
    $begin_runtime = [];

    while($current_timestamp <= $end_timestamp) {
      // Get a new chunk of data.
      if($current_timestamp >= $chunk_end_timestamp) {
        $chunk_end_timestamp = $current_timestamp + $chunk_size;

        $query = '
          select
            `timestamp`,
            `thermostat_id`,
            `indoor_temperature`,
            `outdoor_temperature`,
            `compressor_1`,
            `compressor_2`,
            `compressor_mode`,
            `auxiliary_heat_1`,
            `auxiliary_heat_2`,
            `system_mode`,
            `setpoint_heat`,
            `setpoint_cool`,
            `event_runtime_thermostat_text_id`,
            `climate_runtime_thermostat_text_id`
          from
            `runtime_thermostat`
          where
                `thermostat_id` in (' . implode(',', $thermostat_ids) . ')
            and `timestamp` >= "' . date('Y-m-d H:i:s', ($current_timestamp - $max_lookback)) . '"
            and `timestamp` < "' . date('Y-m-d H:i:s', ($chunk_end_timestamp + $max_lookahead)) . '"
        ';
        $result = $this->database->query($query);

        // Move some things around so that heat/cool/aux columns are
        // consistently represented instead of having to do this logic
        // throughout the generator.
        $runtime = [];
        $degree_days_date = date('Y-m-d', $current_timestamp);
        $degree_days_temperatures = [];
        while($row = $result->fetch_assoc()) {
          $timestamp = strtotime($row['timestamp']);
          $hour = date('G', $timestamp);
          $date = date('Y-m-d', $timestamp);

          // Degree days
          if($date !== $degree_days_date) {
            $degree_days[] = (array_mean($degree_days_temperatures) / 10) - $degree_days_baseline;
            $degree_days_date = $date;
            $degree_days_temperatures = [];
          }
          $degree_days_temperatures[] = $row['outdoor_temperature'];

          if($first_timestamp === null) {
            $first_timestamp = $row['timestamp'];
          }

          // Normalizing heating and cooling a bit.
          if(
            $thermostat['system_type']['detected']['heat'] === 'compressor' ||
            $thermostat['system_type']['detected']['heat'] === 'geothermal'
          ) {
            if($row['compressor_mode'] === 'heat') {
              $row['heat_1'] = $row['compressor_1'];
              $row['heat_2'] = $row['compressor_2'];
            } else {
              $row['heat_1'] = 0;
              $row['heat_2'] = 0;
            }
          } else {
            $row['heat_1'] = $row['auxiliary_heat_1'];
            $row['heat_2'] = $row['auxiliary_heat_2'];
            $row['auxiliary_heat_1'] = 0;
            $row['auxiliary_heat_2'] = 0;
          }

          if($row['compressor_mode'] === 'cool') {
            $row['cool_1'] = $row['compressor_1'];
            $row['cool_2'] = $row['compressor_2'];
          } else {
            $row['cool_1'] = 0;
            $row['cool_2'] = 0;
          }

          $runtime_seconds['heat_1'] += $row['heat_1'];
          $runtime_seconds['heat_2'] += $row['heat_2'];
          $runtime_seconds['auxiliary_heat_1'] += $row['auxiliary_heat_1'];
          $runtime_seconds['auxiliary_heat_2'] += $row['auxiliary_heat_2'];
          $runtime_seconds['cool_1'] += $row['cool_1'];
          $runtime_seconds['cool_2'] += $row['cool_2'];

          if (
            $ignore_solar_heating === true &&
            $hour > 6 &&
            $hour < 22
          ) {
            continue;
          }

          if (isset($runtime[$timestamp]) === false) {
            $runtime[$timestamp] = [];
          }
          $runtime[$timestamp][$row['thermostat_id']] = $row;
        }
      }

      if(
        isset($runtime[$current_timestamp]) === true && // Had data for at least one thermostat
        isset($runtime[$current_timestamp][$thermostat_id]) === true // Had data for the requested thermostat
      ) {
        $current_runtime = $runtime[$current_timestamp][$thermostat_id];
        if($current_runtime['outdoor_temperature'] !== null) {
          // Rounds to the nearest degree (because temperatures are stored in tenths).
          $current_runtime['outdoor_temperature'] = round($current_runtime['outdoor_temperature'] / 10);

          // Applies further smoothing if required.
          $current_runtime['outdoor_temperature'] = round($current_runtime['outdoor_temperature'] / $smoothing) * $smoothing;
        }

        // If the system mode was heat or cool, log the setpoint.
        if($current_runtime['system_mode'] === 'heat') {
          $setpoints['heat'][] = $current_runtime['setpoint_heat'];
        } else if($current_runtime['system_mode'] === 'cool') {
          $setpoints['cool'][] = $current_runtime['setpoint_cool'];
        }

        /**
         * OFF START
         */

        $most_off = true;
        $all_off = true;
        if(
          count($runtime[$current_timestamp]) < count($thermostat_ids)
        ) {
          // If I didn't get data at this timestamp for all thermostats in the
          // group, all off can't be true.
          $all_off = false;
          $most_off = false;
        }
        else {
          foreach($runtime[$current_timestamp] as $runtime_thermostat_id => $thermostat_runtime) {
            if(
              $thermostat_runtime['compressor_1'] !== 0 ||
              $thermostat_runtime['compressor_2'] !== 0 ||
              $thermostat_runtime['auxiliary_heat_1'] !== 0 ||
              $thermostat_runtime['auxiliary_heat_2'] !== 0 ||
              $thermostat_runtime['outdoor_temperature'] === null ||
              $thermostat_runtime['indoor_temperature'] === null
            ) {
              // If I did have data at this timestamp for all thermostats in the
              // group, check and see if were fully off. Also if any of the
              // things used in the algorithm are just missing, assume the
              // system might have been running.
              $all_off = false;

              // If everything _but_ the requested thermostat is off. This is
              // used for the heat/cool scores as I need to only gather samples
              // when everything else is off.
              if($runtime_thermostat_id !== $thermostat_id) {
                $most_off = false;
              }
            }
          }
        }

        // Assume that the runtime rows represent data at the end of that 5
        // minutes.
        if($all_off === true) {
          $all_off_for += $five_minutes;

          // Store the begin runtime row if the system has been off for the
          // requisite length. This gives the temperatures a chance to settle.
          if($all_off_for === $minimum_off_for) {
            $begin_runtime['resist'] = $current_runtime;
          }
        }
        else {
          $all_off_for = 0;
        }

        /**
         * HEAT 1 START
         */

        // Track how long the heat has been on for.
        if($current_runtime['heat_1'] > 0) {
          $heat_1_on_for += $current_runtime['heat_1'];
        } else {
          $heat_1_on_for = 0;
        }

        // Store the begin runtime for heat when the heat has been on for this
        // thermostat only for the required minimum and everything else is off.
        if(
          $most_off === true &&
          $heat_1_on_for >= $minimum_on_for &&
          // $current_runtime['heat_2'] === 0 &&
          $current_runtime['auxiliary_heat_1'] === 0 &&
          $current_runtime['auxiliary_heat_2'] === 0 &&
          isset($begin_runtime['heat_1']) === false
        ) {
          $begin_runtime['heat_1'] = $current_runtime;
        }

        /**
         * HEAT 2 START
         */

        // Track how long the heat has been on for.
        if($current_runtime['heat_2'] > 0) {
          $heat_2_on_for += $current_runtime['heat_2'];
        } else {
          $heat_2_on_for = 0;
        }

        // Store the begin runtime for heat when the heat has been on for this
        // thermostat only for the required minimum and everything else is off.
        if(
          $most_off === true &&
          $heat_2_on_for >= $minimum_on_for &&
          // $current_runtime['heat_1'] === 0 &&
          $current_runtime['auxiliary_heat_1'] === 0 &&
          $current_runtime['auxiliary_heat_2'] === 0 &&
          isset($begin_runtime['heat_2']) === false
        ) {
          $begin_runtime['heat_2'] = $current_runtime;
        }

        /**
         * COOL 1 START
         */

        // Track how long the cool has been on for.
        if($current_runtime['cool_1'] > 0) {
          $cool_1_on_for += $current_runtime['cool_1'];
        } else {
          $cool_1_on_for = 0;
        }

        // Store the begin runtime for cool when the cool has been on for this
        // thermostat only for the required minimum and everything else is off.
        if(
          $most_off === true &&
          $cool_1_on_for >= $minimum_on_for &&
          $current_runtime['cool_2'] === 0 &&
          isset($begin_runtime['cool_1']) === false
        ) {
          $begin_runtime['cool_1'] = $current_runtime;
        }

        /**
         * COOL 2 START
         */

        // Track how long the cool has been on for.
        if($current_runtime['cool_2'] > 0) {
          $cool_2_on_for += $current_runtime['cool_2'];
        } else {
          $cool_2_on_for = 0;
        }

        // Store the begin runtime for cool when the cool has been on for this
        // thermostat only for the required minimum and everything else is off.
        if(
          $most_off === true &&
          $cool_2_on_for >= $minimum_on_for &&
          // $current_runtime['cool_1'] === 0 &&
          isset($begin_runtime['cool_2']) === false
        ) {
          $begin_runtime['cool_2'] = $current_runtime;
        }

        // Look for changes which would trigger a sample to be gathered.
        if(
          (
            // Heat 1
            // Gather a "heat_1" delta for one of the following reasons.
            // - The outdoor temperature changed
            // - The calendar event changed
            // - The climate changed
            // - One of the other thermostats in this group turned on
            ($sample_type = 'heat_1') &&
            isset($begin_runtime['heat_1']) === true &&
            isset($previous_runtime) === true &&
            (
              $current_runtime['outdoor_temperature'] !== $begin_runtime['heat_1']['outdoor_temperature'] ||
              $current_runtime['event_runtime_thermostat_text_id'] !== $begin_runtime['heat_1']['event_runtime_thermostat_text_id'] ||
              $current_runtime['climate_runtime_thermostat_text_id'] !== $begin_runtime['heat_1']['climate_runtime_thermostat_text_id'] ||
              $most_off === false
            )
          ) ||
          (
            // Heat 1
            // Gather a "heat_2" delta for one of the following reasons.
            // - The outdoor temperature changed
            // - The calendar event changed
            // - The climate changed
            // - One of the other thermostats in this group turned on
            ($sample_type = 'heat_2') &&
            isset($begin_runtime['heat_2']) === true &&
            isset($previous_runtime) === true &&
            (
              $current_runtime['outdoor_temperature'] !== $begin_runtime['heat_2']['outdoor_temperature'] ||
              $current_runtime['event_runtime_thermostat_text_id'] !== $begin_runtime['heat_2']['event_runtime_thermostat_text_id'] ||
              $current_runtime['climate_runtime_thermostat_text_id'] !== $begin_runtime['heat_2']['climate_runtime_thermostat_text_id'] ||
              $most_off === false
            )
          ) ||
          (
            // Cool
            // Gather a "cool_1" delta for one of the following reasons.
            // - The outdoor temperature changed
            // - The calendar event changed
            // - The climate changed
            // - One of the other thermostats in this group turned on
            ($sample_type = 'cool_1') &&
            isset($begin_runtime['cool_1']) === true &&
            isset($previous_runtime) === true &&
            (
              $current_runtime['outdoor_temperature'] !== $begin_runtime['cool_1']['outdoor_temperature'] ||
              $current_runtime['event_runtime_thermostat_text_id'] !== $begin_runtime['cool_1']['event_runtime_thermostat_text_id'] ||
              $current_runtime['climate_runtime_thermostat_text_id'] !== $begin_runtime['cool_1']['climate_runtime_thermostat_text_id'] ||
              $most_off === false
            )
          ) ||
          (
            // Cool
            // Gather a "cool_2" delta for one of the following reasons.
            // - The outdoor temperature changed
            // - The calendar event changed
            // - The climate changed
            // - One of the other thermostats in this group turned on
            ($sample_type = 'cool_2') &&
            isset($begin_runtime['cool_2']) === true &&
            isset($previous_runtime) === true &&
            (
              $current_runtime['outdoor_temperature'] !== $begin_runtime['cool_2']['outdoor_temperature'] ||
              $current_runtime['event_runtime_thermostat_text_id'] !== $begin_runtime['cool_2']['event_runtime_thermostat_text_id'] ||
              $current_runtime['climate_runtime_thermostat_text_id'] !== $begin_runtime['cool_2']['climate_runtime_thermostat_text_id'] ||
              $most_off === false
            )
          ) ||
          (
            // Resist
            // Gather an "off" delta for one of the following reasons.
            // - The outdoor temperature changed
            // - The calendar event changed
            // - The climate changed
            // - The system turned back on after being off
            ($sample_type = 'resist') &&
            isset($begin_runtime['resist']) === true &&
            isset($previous_runtime) === true &&
            (
              $current_runtime['outdoor_temperature'] !== $begin_runtime['resist']['outdoor_temperature'] ||
              $current_runtime['event_runtime_thermostat_text_id'] !== $begin_runtime['resist']['event_runtime_thermostat_text_id'] ||
              $current_runtime['climate_runtime_thermostat_text_id'] !== $begin_runtime['resist']['climate_runtime_thermostat_text_id'] ||
              $all_off === false
            )
          )
        ) {
          // By default the end sample is the previous sample (five minutes ago).
          $offset = $five_minutes;

          // If event_runtime_thermostat_text_id or climate_runtime_thermostat_text_id changes, need to ignore data
          // from the previous 30 minutes as there are sensors changing during
          // that time.
          if(
            $current_runtime['event_runtime_thermostat_text_id'] !== $begin_runtime[$sample_type]['event_runtime_thermostat_text_id'] ||
            $current_runtime['climate_runtime_thermostat_text_id'] !== $begin_runtime[$sample_type]['climate_runtime_thermostat_text_id']
          ) {
            $offset = $thirty_minutes;
          } else {
            // Start looking ahead into the next 30 minutes looking for changes
            // to event_runtime_thermostat_text_id and climate_runtime_thermostat_text_id.
            $lookahead = $five_minutes;
            while($lookahead <= $thirty_minutes) {
              if(
                isset($runtime[$current_timestamp + $lookahead]) === true &&
                isset($runtime[$current_timestamp + $lookahead][$thermostat_id]) === true &&
                (
                  $runtime[$current_timestamp + $lookahead][$thermostat_id]['event_runtime_thermostat_text_id'] !== $current_runtime['event_runtime_thermostat_text_id'] ||
                  $runtime[$current_timestamp + $lookahead][$thermostat_id]['climate_runtime_thermostat_text_id'] !== $current_runtime['climate_runtime_thermostat_text_id']
                )
              ) {
                $offset = ($thirty_minutes - $lookahead);
                break;
              }

              $lookahead += $five_minutes;
            }
          }

          // Now use the offset to set the proper end_runtime. This simply makes
          // sure the data is present and then uses it. In the case where the
          // desired data is missing, I *could* look back further but I'm not
          // going to bother. It's pretty rare and adds some unwanted complexity
          // to this.
          if(
            isset($runtime[$current_timestamp - $offset]) === true &&
            isset($runtime[$current_timestamp - $offset][$thermostat_id]) === true &&
            ($current_timestamp - $offset) > strtotime($begin_runtime[$sample_type]['timestamp'])
          ) {
            $end_runtime = $runtime[$current_timestamp - $offset][$thermostat_id];
          } else {
            $end_runtime = null;
          }

          if($end_runtime !== null) {
            $delta = $end_runtime['indoor_temperature'] - $begin_runtime[$sample_type]['indoor_temperature'];
            $duration = strtotime($end_runtime['timestamp']) - strtotime($begin_runtime[$sample_type]['timestamp']);

            if($duration > 0) {
              $sample = [
                'type' => $sample_type,
                'outdoor_temperature' => $begin_runtime[$sample_type]['outdoor_temperature'],
                'delta' => $delta,
                'duration' => $duration,
                'delta_per_hour' => $delta / $duration * 3600,
              ];
              $samples[] = $sample;
            }
          }

          // If in this block of code a change in runtime was detected, so
          // update $begin_runtime[$sample_type] to the current runtime.
          $begin_runtime[$sample_type] = $current_runtime;
        }

        $previous_runtime = $current_runtime;
      }

      // After a change was detected it automatically moves begin to the
      // current_runtime to start a new sample. This might be invalid so need to
      // unset it if so.
      if(
        $heat_1_on_for === 0 ||
        $current_runtime['outdoor_temperature'] === null ||
        $current_runtime['indoor_temperature'] === null ||
        $current_runtime['auxiliary_heat_1'] > 0 ||
        $current_runtime['auxiliary_heat_2'] > 0
      ) {
        unset($begin_runtime['heat_1']);
      }
      if(
        $heat_2_on_for === 0 ||
        $current_runtime['outdoor_temperature'] === null ||
        $current_runtime['indoor_temperature'] === null ||
        $current_runtime['auxiliary_heat_1'] > 0 ||
        $current_runtime['auxiliary_heat_2'] > 0
      ) {
        unset($begin_runtime['heat_2']);
      }
      if(
        $cool_1_on_for === 0 ||
        $current_runtime['outdoor_temperature'] === null ||
        $current_runtime['indoor_temperature'] === null
      ) {
        unset($begin_runtime['cool_1']);
      }
      if(
        $cool_2_on_for === 0 ||
        $current_runtime['outdoor_temperature'] === null ||
        $current_runtime['indoor_temperature'] === null
      ) {
        unset($begin_runtime['cool_2']);
      }
      if($all_off_for === 0) {
        unset($begin_runtime['resist']);
      }

      $current_timestamp += $five_minutes;
    }

    // Process the samples
    $deltas_raw = [];
    foreach($samples as $sample) {
      $is_valid_sample = true;
      if($sample['duration'] < $minimum_sample_duration[$sample['type']]) {
        $is_valid_sample = false;
      }

      if($is_valid_sample === true) {
        if(isset($deltas_raw[$sample['type']]) === false) {
          $deltas_raw[$sample['type']] = [];
        }
        if(isset($deltas_raw[$sample['type']][$sample['outdoor_temperature']]) === false) {
          $deltas_raw[$sample['type']][$sample['outdoor_temperature']] = [
            'deltas_per_hour' => []
          ];
        }

        $deltas_raw[$sample['type']][$sample['outdoor_temperature']]['deltas_per_hour'][] = $sample['delta_per_hour'];
      }
    }

    // Generate the final profile and save it.
    $profile = [
      'temperature' => [
        'heat_1' => null,
        'heat_2' => null,
        'auxiliary_heat_1' => null,
        'auxiliary_heat_2' => null,
        'cool_1' => null,
        'cool_2' => null,
        'resist' => null
      ],
      'setpoint' => [
        'heat' => null,
        'cool' => null
      ],
      'degree_days' => [
        'heat' => null,
        'cool' => null
      ],
      'runtime' => [
        'heat_1' => round($runtime_seconds['heat_1'] / 3600),
        'heat_2' => round($runtime_seconds['heat_2'] / 3600),
        'auxiliary_heat_1' => round($runtime_seconds['auxiliary_heat_1'] / 3600),
        'auxiliary_heat_2' => round($runtime_seconds['auxiliary_heat_2'] / 3600),
        'cool_1' => round($runtime_seconds['cool_1'] / 3600),
        'cool_2' => round($runtime_seconds['cool_2'] / 3600),
      ],
      'metadata' => [
        'generated_at' => date('c'),
        'duration' => round((time() - strtotime($first_timestamp)) / 86400),
        'temperature' => [
          'heat_1' => [
            'deltas' => []
          ],
          'heat_2' => [
            'deltas' => []
          ],
          'auxiliary_heat_1' => [
            'deltas' => []
          ],
          'auxiliary_heat_2' => [
            'deltas' => []
          ],
          'cool_1' => [
            'deltas' => []
          ],
          'cool_2' => [
            'deltas' => []
          ],
          'resist' => [
            'deltas' => []
          ]
        ]
      ]
    ];

    $deltas = [];
    foreach($deltas_raw as $type => $raw) {
      if(isset($deltas[$type]) === false) {
        $deltas[$type] = [];
      }
      foreach($raw as $outdoor_temperature => $data) {
        if(
          isset($deltas[$type][$outdoor_temperature]) === false &&
          count($data['deltas_per_hour']) >= $required_samples
        ) {
          $deltas[$type][$outdoor_temperature] = round(array_median($data['deltas_per_hour']) / 10, 2);
          $profile['metadata']['temperature'][$type]['deltas'][$outdoor_temperature]['samples'] = count($data['deltas_per_hour']);
        }
      }
    }

    foreach($deltas as $type => $data) {
      if(count($data) < $required_points) {
        continue;
      }

      ksort($deltas[$type]);

      $profile['temperature'][$type] = [
        'deltas' => $deltas[$type],
        'linear_trendline' => $this->get_linear_trendline($deltas[$type])
      ];
    }

    foreach(['heat', 'cool'] as $type) {
      if(count($setpoints[$type]) > 0) {
        $profile['setpoint'][$type] = round(array_mean($setpoints[$type])) / 10;
        $profile['metadata']['setpoint'][$type]['samples'] = count($setpoints[$type]);
      }
    }

    // Heating and cooling degree days.
    foreach($degree_days as $degree_day) {
      if($degree_day < 0) {
        $profile['degree_days']['cool'] += ($degree_day * -1);
      } else {
        $profile['degree_days']['heat'] += ($degree_day);
      }
    }
    if ($profile['degree_days']['cool'] !== null) {
      $profile['degree_days']['cool'] = round($profile['degree_days']['cool']);
    }
    if ($profile['degree_days']['heat'] !== null) {
      $profile['degree_days']['heat'] = round($profile['degree_days']['heat']);
    }


    return $profile;
  }

  /**
   * Get the properties of a linear trendline for a given set of data.
   *
   * @param array $data
   *
   * @return array [slope, intercept]
   */
  public function get_linear_trendline($data) {
    // Requires at least two points.
    if(count($data) < 2) {
      return null;
    }

    $sum_x = 0;
    $sum_y = 0;
    $sum_xy = 0;
    $sum_x_squared = 0;
    $n = 0;

    foreach($data as $x => $y) {
      $sum_x += $x;
      $sum_y += $y;
      $sum_xy += ($x * $y);
      $sum_x_squared += pow($x, 2);
      $n++;
    }

    $slope = (($n * $sum_xy) - ($sum_x * $sum_y)) / (($n * $sum_x_squared) - (pow($sum_x, 2)));
    $intercept = (($sum_y) - ($slope * $sum_x)) / ($n);

    return [
      'slope' => round($slope, 2),
      'intercept' => round($intercept, 2)
    ];
  }
}
