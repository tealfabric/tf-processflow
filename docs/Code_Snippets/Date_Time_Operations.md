# Date & Time Operations

This guide covers date and time manipulation techniques within ProcessFlow code snippets.

## Overview

Date and time operations are essential for scheduling, reporting, data processing, and business logic. These are **idempotent operations** that work with temporal data.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Date/Time      │────▶│  Date Processing │────▶│  Formatted      │
│  Input          │     │  Code Snippet    │     │  Output         │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available PHP Date Functions

| Category | Functions |
|----------|-----------|
| **Current** | `time`, `date`, `gmdate`, `microtime` |
| **Parse** | `strtotime`, `date_parse`, `date_parse_from_format` |
| **Format** | `date`, `gmdate`, `strftime` |
| **Create** | `mktime`, `gmmktime` |
| **Calculate** | `strtotime` (relative dates) |
| **Timezone** | `date_default_timezone_get`, `timezone_identifiers_list` |
| **Validation** | `checkdate` |

---

## Example 1: Date Formatting

Format dates in various formats:

```php
<?php
$input = $process_input['result']['date'] ?? 'now';
$outputFormat = $process_input['result']['format'] ?? 'Y-m-d H:i:s';
$timezone = $process_input['result']['timezone'] ?? 'UTC';

// Parse input date
$timestamp = is_numeric($input) ? (int)$input : strtotime($input);

if ($timestamp === false) {
    return ['success' => false, 'error' => 'Invalid date input: ' . $input];
}

// Set timezone
$originalTz = date_default_timezone_get();
date_default_timezone_set($timezone);

// Format in various ways
$formatted = [
    'input' => $input,
    'timestamp' => $timestamp,
    'custom' => date($outputFormat, $timestamp),
    'iso8601' => date('c', $timestamp),
    'rfc2822' => date('r', $timestamp),
    'date_only' => date('Y-m-d', $timestamp),
    'time_only' => date('H:i:s', $timestamp),
    'human_readable' => date('F j, Y, g:i a', $timestamp),
    'day_of_week' => date('l', $timestamp),
    'week_number' => date('W', $timestamp),
    'day_of_year' => date('z', $timestamp),
    'timezone' => $timezone
];

// Restore timezone
date_default_timezone_set($originalTz);

return [
    'success' => true,
    'data' => $formatted
];
```

---

## Example 2: Date Parsing

Parse dates from various formats:

```php
<?php
$dateString = $process_input['result']['date_string'] ?? '';
$expectedFormat = $process_input['result']['expected_format'] ?? null;

if (empty($dateString)) {
    return ['success' => false, 'error' => 'No date string provided'];
}

$result = [
    'input' => $dateString,
    'parsed' => false,
    'timestamp' => null,
    'components' => null
];

// Try parsing with expected format first
if ($expectedFormat) {
    $parsed = date_parse_from_format($expectedFormat, $dateString);
    if ($parsed['error_count'] === 0) {
        $timestamp = mktime(
            $parsed['hour'] ?? 0,
            $parsed['minute'] ?? 0,
            $parsed['second'] ?? 0,
            $parsed['month'] ?? 1,
            $parsed['day'] ?? 1,
            $parsed['year'] ?? date('Y')
        );
        $result['timestamp'] = $timestamp;
        $result['components'] = $parsed;
        $result['parsed'] = true;
    }
}

// Fallback to strtotime
if (!$result['parsed']) {
    $timestamp = strtotime($dateString);
    if ($timestamp !== false) {
        $result['timestamp'] = $timestamp;
        $result['components'] = date_parse($dateString);
        $result['parsed'] = true;
    }
}

// Add formatted outputs if parsed successfully
if ($result['parsed']) {
    $result['formatted'] = [
        'iso' => date('Y-m-d\TH:i:sP', $result['timestamp']),
        'date' => date('Y-m-d', $result['timestamp']),
        'time' => date('H:i:s', $result['timestamp']),
        'readable' => date('F j, Y g:i A', $result['timestamp'])
    ];
}

return [
    'success' => $result['parsed'],
    'data' => $result,
    'error' => $result['parsed'] ? null : 'Could not parse date: ' . $dateString
];
```

---

## Example 3: Date Arithmetic

Add or subtract time from dates:

```php
<?php
$baseDate = $process_input['result']['date'] ?? 'now';
$operation = $process_input['result']['operation'] ?? 'add';
$amount = (int)($process_input['result']['amount'] ?? 1);
$unit = $process_input['result']['unit'] ?? 'days';

// Parse base date
$timestamp = is_numeric($baseDate) ? (int)$baseDate : strtotime($baseDate);

if ($timestamp === false) {
    return ['success' => false, 'error' => 'Invalid base date'];
}

// Build modification string
$sign = ($operation === 'subtract') ? '-' : '+';
$modString = "{$sign}{$amount} {$unit}";

// Calculate new date
$newTimestamp = strtotime($modString, $timestamp);

if ($newTimestamp === false) {
    return ['success' => false, 'error' => 'Invalid date calculation'];
}

// Calculate difference
$diffSeconds = abs($newTimestamp - $timestamp);
$diffDays = floor($diffSeconds / 86400);

return [
    'success' => true,
    'data' => [
        'original' => [
            'timestamp' => $timestamp,
            'formatted' => date('Y-m-d H:i:s', $timestamp)
        ],
        'operation' => $operation,
        'amount' => $amount,
        'unit' => $unit,
        'result' => [
            'timestamp' => $newTimestamp,
            'formatted' => date('Y-m-d H:i:s', $newTimestamp),
            'iso' => date('c', $newTimestamp)
        ],
        'difference' => [
            'seconds' => $diffSeconds,
            'days' => $diffDays
        ]
    ]
];
```

---

## Example 4: Date Difference Calculation

Calculate the difference between two dates:

```php
<?php
$startDate = $process_input['result']['start_date'] ?? '';
$endDate = $process_input['result']['end_date'] ?? 'now';

// Parse dates
$startTimestamp = is_numeric($startDate) ? (int)$startDate : strtotime($startDate);
$endTimestamp = is_numeric($endDate) ? (int)$endDate : strtotime($endDate);

if ($startTimestamp === false || $endTimestamp === false) {
    return ['success' => false, 'error' => 'Invalid date(s) provided'];
}

// Ensure start is before end
$isReversed = $startTimestamp > $endTimestamp;
if ($isReversed) {
    [$startTimestamp, $endTimestamp] = [$endTimestamp, $startTimestamp];
}

// Calculate differences
$diffSeconds = $endTimestamp - $startTimestamp;
$diffMinutes = floor($diffSeconds / 60);
$diffHours = floor($diffSeconds / 3600);
$diffDays = floor($diffSeconds / 86400);
$diffWeeks = floor($diffDays / 7);
$diffMonths = floor($diffDays / 30.44); // Average days per month
$diffYears = floor($diffDays / 365.25);

// Human-readable breakdown
$remaining = $diffSeconds;
$years = floor($remaining / 31536000);
$remaining %= 31536000;
$months = floor($remaining / 2628000);
$remaining %= 2628000;
$days = floor($remaining / 86400);
$remaining %= 86400;
$hours = floor($remaining / 3600);
$remaining %= 3600;
$minutes = floor($remaining / 60);
$seconds = $remaining % 60;

return [
    'success' => true,
    'data' => [
        'start' => date('Y-m-d H:i:s', $startTimestamp),
        'end' => date('Y-m-d H:i:s', $endTimestamp),
        'is_reversed' => $isReversed,
        'total' => [
            'seconds' => $diffSeconds,
            'minutes' => $diffMinutes,
            'hours' => $diffHours,
            'days' => $diffDays,
            'weeks' => $diffWeeks,
            'months' => $diffMonths,
            'years' => $diffYears
        ],
        'breakdown' => [
            'years' => $years,
            'months' => $months,
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes,
            'seconds' => $seconds
        ],
        'human_readable' => sprintf(
            '%d years, %d months, %d days, %d hours, %d minutes',
            $years, $months, $days, $hours, $minutes
        )
    ]
];
```

---

## Example 5: Timezone Conversion

Convert dates between timezones:

```php
<?php
$dateTime = $process_input['result']['datetime'] ?? 'now';
$fromTimezone = $process_input['result']['from_timezone'] ?? 'UTC';
$toTimezone = $process_input['result']['to_timezone'] ?? 'Europe/Helsinki';

// Parse the date in source timezone
$originalTz = date_default_timezone_get();
date_default_timezone_set($fromTimezone);

$timestamp = is_numeric($dateTime) ? (int)$dateTime : strtotime($dateTime);

if ($timestamp === false) {
    date_default_timezone_set($originalTz);
    return ['success' => false, 'error' => 'Invalid datetime'];
}

$sourceFormatted = date('Y-m-d H:i:s T', $timestamp);

// Get UTC timestamp (timezone-independent)
$utcTimestamp = $timestamp - date('Z', $timestamp);

// Convert to target timezone
date_default_timezone_set($toTimezone);
$targetFormatted = date('Y-m-d H:i:s T', $timestamp);
$targetOffset = date('P', $timestamp);

// Restore original timezone
date_default_timezone_set($originalTz);

return [
    'success' => true,
    'data' => [
        'input' => $dateTime,
        'source' => [
            'timezone' => $fromTimezone,
            'formatted' => $sourceFormatted
        ],
        'target' => [
            'timezone' => $toTimezone,
            'formatted' => $targetFormatted,
            'offset' => $targetOffset
        ],
        'utc' => date('Y-m-d H:i:s', $utcTimestamp) . ' UTC',
        'timestamp' => $timestamp
    ]
];
```

---

## Example 6: Business Days Calculation

Calculate business days excluding weekends:

```php
<?php
$startDate = $process_input['result']['start_date'] ?? 'now';
$days = (int)($process_input['result']['business_days'] ?? 5);
$excludeWeekends = $process_input['result']['exclude_weekends'] ?? true;
$holidays = $process_input['result']['holidays'] ?? []; // Array of dates 'Y-m-d'

$timestamp = is_numeric($startDate) ? (int)$startDate : strtotime($startDate);

if ($timestamp === false) {
    return ['success' => false, 'error' => 'Invalid start date'];
}

$currentDate = $timestamp;
$businessDaysAdded = 0;
$totalDays = 0;
$skippedDays = [];

while ($businessDaysAdded < $days) {
    $currentDate = strtotime('+1 day', $currentDate);
    $totalDays++;
    
    $dayOfWeek = date('N', $currentDate); // 1=Monday, 7=Sunday
    $dateString = date('Y-m-d', $currentDate);
    
    $isWeekend = ($dayOfWeek >= 6);
    $isHoliday = in_array($dateString, $holidays);
    
    if ($excludeWeekends && $isWeekend) {
        $skippedDays[] = [
            'date' => $dateString,
            'reason' => 'weekend',
            'day' => date('l', $currentDate)
        ];
        continue;
    }
    
    if ($isHoliday) {
        $skippedDays[] = [
            'date' => $dateString,
            'reason' => 'holiday',
            'day' => date('l', $currentDate)
        ];
        continue;
    }
    
    $businessDaysAdded++;
}

return [
    'success' => true,
    'data' => [
        'start_date' => date('Y-m-d', $timestamp),
        'business_days_requested' => $days,
        'result_date' => date('Y-m-d', $currentDate),
        'result_day' => date('l', $currentDate),
        'total_calendar_days' => $totalDays,
        'skipped_days_count' => count($skippedDays),
        'skipped_days' => $skippedDays
    ]
];
```

---

## Example 7: Date Range Generation

Generate a range of dates:

```php
<?php
$startDate = $process_input['result']['start_date'] ?? 'now';
$endDate = $process_input['result']['end_date'] ?? '+7 days';
$interval = $process_input['result']['interval'] ?? '1 day';
$format = $process_input['result']['format'] ?? 'Y-m-d';

$startTimestamp = is_numeric($startDate) ? (int)$startDate : strtotime($startDate);
$endTimestamp = is_numeric($endDate) ? (int)$endDate : strtotime($endDate);

if ($startTimestamp === false || $endTimestamp === false) {
    return ['success' => false, 'error' => 'Invalid date(s)'];
}

// Ensure start is before end
if ($startTimestamp > $endTimestamp) {
    [$startTimestamp, $endTimestamp] = [$endTimestamp, $startTimestamp];
}

$dates = [];
$currentTimestamp = $startTimestamp;
$maxIterations = 1000; // Safety limit
$iteration = 0;

while ($currentTimestamp <= $endTimestamp && $iteration < $maxIterations) {
    $dates[] = [
        'date' => date($format, $currentTimestamp),
        'timestamp' => $currentTimestamp,
        'day_of_week' => date('l', $currentTimestamp),
        'week_number' => date('W', $currentTimestamp),
        'is_weekend' => (date('N', $currentTimestamp) >= 6)
    ];
    
    $currentTimestamp = strtotime('+' . $interval, $currentTimestamp);
    $iteration++;
}

return [
    'success' => true,
    'data' => [
        'start' => date($format, $startTimestamp),
        'end' => date($format, $endTimestamp),
        'interval' => $interval,
        'count' => count($dates),
        'dates' => $dates
    ]
];
```

---

## Example 8: Relative Date Descriptions

Convert dates to human-readable relative descriptions:

```php
<?php
$dateTime = $process_input['result']['datetime'] ?? 'now';
$referenceTime = $process_input['result']['reference'] ?? 'now';

$timestamp = is_numeric($dateTime) ? (int)$dateTime : strtotime($dateTime);
$referenceTimestamp = is_numeric($referenceTime) ? (int)$referenceTime : strtotime($referenceTime);

if ($timestamp === false || $referenceTimestamp === false) {
    return ['success' => false, 'error' => 'Invalid date(s)'];
}

$diff = $timestamp - $referenceTimestamp;
$absDiff = abs($diff);
$isPast = $diff < 0;

// Calculate relative description
$intervals = [
    31536000 => 'year',
    2592000 => 'month',
    604800 => 'week',
    86400 => 'day',
    3600 => 'hour',
    60 => 'minute',
    1 => 'second'
];

$relativeText = 'just now';

foreach ($intervals as $seconds => $unit) {
    $value = floor($absDiff / $seconds);
    if ($value >= 1) {
        $plural = ($value > 1) ? 's' : '';
        if ($isPast) {
            $relativeText = "{$value} {$unit}{$plural} ago";
        } else {
            $relativeText = "in {$value} {$unit}{$plural}";
        }
        break;
    }
}

// Specific descriptions for common cases
if ($absDiff < 60) {
    $relativeText = $isPast ? 'just now' : 'in a moment';
} elseif (date('Y-m-d', $timestamp) === date('Y-m-d', $referenceTimestamp)) {
    $relativeText = 'today at ' . date('g:i A', $timestamp);
} elseif (date('Y-m-d', $timestamp) === date('Y-m-d', strtotime('-1 day', $referenceTimestamp))) {
    $relativeText = 'yesterday at ' . date('g:i A', $timestamp);
} elseif (date('Y-m-d', $timestamp) === date('Y-m-d', strtotime('+1 day', $referenceTimestamp))) {
    $relativeText = 'tomorrow at ' . date('g:i A', $timestamp);
}

return [
    'success' => true,
    'data' => [
        'datetime' => date('Y-m-d H:i:s', $timestamp),
        'reference' => date('Y-m-d H:i:s', $referenceTimestamp),
        'relative' => $relativeText,
        'is_past' => $isPast,
        'difference_seconds' => $diff
    ]
];
```

---

## Example 9: Schedule/Cron Time Checking

Check if current time matches a schedule:

```php
<?php
/**
 * Check if current time matches a simple schedule pattern
 * Pattern format: "HH:MM" or "weekday HH:MM" or "day-of-month HH:MM"
 */

$schedule = $process_input['result']['schedule'] ?? '09:00';
$checkTime = $process_input['result']['check_time'] ?? 'now';
$toleranceMinutes = (int)($process_input['result']['tolerance_minutes'] ?? 5);

$checkTimestamp = is_numeric($checkTime) ? (int)$checkTime : strtotime($checkTime);

if ($checkTimestamp === false) {
    return ['success' => false, 'error' => 'Invalid check time'];
}

$currentHour = (int)date('H', $checkTimestamp);
$currentMinute = (int)date('i', $checkTimestamp);
$currentDayOfWeek = strtolower(date('l', $checkTimestamp));
$currentDayOfMonth = (int)date('j', $checkTimestamp);

// Parse schedule
$parts = explode(' ', trim($schedule));
$matches = false;
$matchReason = '';

if (count($parts) === 1) {
    // Just time: "09:00"
    $timeParts = explode(':', $parts[0]);
    $schedHour = (int)$timeParts[0];
    $schedMinute = (int)($timeParts[1] ?? 0);
    
    $diffMinutes = abs(($currentHour * 60 + $currentMinute) - ($schedHour * 60 + $schedMinute));
    $matches = ($diffMinutes <= $toleranceMinutes);
    $matchReason = $matches ? 'Time matches' : 'Time does not match';
    
} elseif (count($parts) === 2) {
    // Weekday or day + time: "monday 09:00" or "15 09:00"
    $dayPart = strtolower($parts[0]);
    $timeParts = explode(':', $parts[1]);
    $schedHour = (int)$timeParts[0];
    $schedMinute = (int)($timeParts[1] ?? 0);
    
    $dayMatches = false;
    if (is_numeric($dayPart)) {
        // Day of month
        $dayMatches = ((int)$dayPart === $currentDayOfMonth);
    } else {
        // Day of week
        $dayMatches = ($dayPart === $currentDayOfWeek);
    }
    
    $diffMinutes = abs(($currentHour * 60 + $currentMinute) - ($schedHour * 60 + $schedMinute));
    $timeMatches = ($diffMinutes <= $toleranceMinutes);
    
    $matches = ($dayMatches && $timeMatches);
    $matchReason = $dayMatches 
        ? ($timeMatches ? 'Day and time match' : 'Day matches but time does not')
        : 'Day does not match';
}

return [
    'success' => true,
    'data' => [
        'schedule' => $schedule,
        'check_time' => date('Y-m-d H:i:s', $checkTimestamp),
        'matches' => $matches,
        'reason' => $matchReason,
        'current' => [
            'day_of_week' => $currentDayOfWeek,
            'day_of_month' => $currentDayOfMonth,
            'time' => sprintf('%02d:%02d', $currentHour, $currentMinute)
        ],
        'tolerance_minutes' => $toleranceMinutes
    ]
];
```

---

## Example 10: Fiscal Period Calculation

Determine fiscal year, quarter, and period:

```php
<?php
$date = $process_input['result']['date'] ?? 'now';
$fiscalYearStart = $process_input['result']['fiscal_year_start'] ?? 1; // Month (1-12)

$timestamp = is_numeric($date) ? (int)$date : strtotime($date);

if ($timestamp === false) {
    return ['success' => false, 'error' => 'Invalid date'];
}

$year = (int)date('Y', $timestamp);
$month = (int)date('n', $timestamp);
$day = (int)date('j', $timestamp);

// Calculate fiscal year
$fiscalYear = $year;
if ($month < $fiscalYearStart) {
    $fiscalYear = $year - 1;
}

// Calculate fiscal month (1-12 from start of fiscal year)
$fiscalMonth = $month - $fiscalYearStart + 1;
if ($fiscalMonth <= 0) {
    $fiscalMonth += 12;
}

// Calculate fiscal quarter
$fiscalQuarter = ceil($fiscalMonth / 3);

// Calculate fiscal half
$fiscalHalf = ($fiscalMonth <= 6) ? 1 : 2;

// Calculate days in fiscal year
$fiscalYearStartDate = mktime(0, 0, 0, $fiscalYearStart, 1, $fiscalYear);
$fiscalYearEndDate = strtotime('+1 year -1 day', $fiscalYearStartDate);
$daysInFiscalYear = floor(($fiscalYearEndDate - $fiscalYearStartDate) / 86400) + 1;

// Days elapsed in fiscal year
$daysElapsed = floor(($timestamp - $fiscalYearStartDate) / 86400) + 1;

// Progress through fiscal year
$yearProgress = round(($daysElapsed / $daysInFiscalYear) * 100, 2);

return [
    'success' => true,
    'data' => [
        'date' => date('Y-m-d', $timestamp),
        'calendar' => [
            'year' => $year,
            'month' => $month,
            'quarter' => ceil($month / 3)
        ],
        'fiscal' => [
            'year' => $fiscalYear,
            'year_label' => "FY{$fiscalYear}",
            'month' => $fiscalMonth,
            'quarter' => $fiscalQuarter,
            'quarter_label' => "Q{$fiscalQuarter}",
            'half' => $fiscalHalf,
            'half_label' => "H{$fiscalHalf}"
        ],
        'progress' => [
            'days_elapsed' => $daysElapsed,
            'days_total' => $daysInFiscalYear,
            'percent_complete' => $yearProgress
        ],
        'fiscal_year_config' => [
            'start_month' => $fiscalYearStart,
            'start_date' => date('Y-m-d', $fiscalYearStartDate),
            'end_date' => date('Y-m-d', $fiscalYearEndDate)
        ]
    ]
];
```

---

## Best Practices

### 1. Always Validate Date Inputs
```php
$timestamp = strtotime($input);
if ($timestamp === false) {
    return ['success' => false, 'error' => 'Invalid date'];
}
```

### 2. Be Explicit About Timezones
```php
// Store and pass timezone with dates
$data = [
    'timestamp' => time(),
    'timezone' => date_default_timezone_get()
];
```

### 3. Use ISO 8601 for Data Exchange
```php
// Standard format: 2024-12-18T19:30:00+02:00
$iso = date('c', $timestamp);
```

### 4. Handle Edge Cases
```php
// Check for valid dates
if (!checkdate($month, $day, $year)) {
    return ['success' => false, 'error' => 'Invalid date'];
}
```

---

## Common Formats Reference

| Format | Example | PHP Code |
|--------|---------|----------|
| ISO 8601 | 2024-12-18T19:30:00+00:00 | `date('c')` |
| RFC 2822 | Wed, 18 Dec 2024 19:30:00 +0000 | `date('r')` |
| MySQL | 2024-12-18 19:30:00 | `date('Y-m-d H:i:s')` |
| Date only | 2024-12-18 | `date('Y-m-d')` |
| Time only | 19:30:00 | `date('H:i:s')` |
| Human | December 18, 2024 | `date('F j, Y')` |

---

## See Also

- [Data Transformation & Mapping](Data_Transformation_Mapping.md)
- [Mathematical & Statistical Calculations](Math_Statistics.md)
- [Conditional Logic & Routing](Conditional_Logic_Routing.md)
