# Mathematical & Statistical Calculations

This guide covers mathematical and statistical operations within ProcessFlow code snippets. The step contract uses **`$process_input`** for data from the previous step ([interface v1](../interface/v1/variables.md)). In the examples below, **`$input`** is used as a local shorthand for `$process_input['result']` (or `$process_input`) where the step receives a nested payload.

## Overview

Mathematical operations are essential for financial calculations, analytics, reporting, and data analysis. These are **idempotent operations** that produce deterministic results.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Numeric Data   │────▶│  Math/Stats      │────▶│  Calculated     │
│  Input          │     │  Code Snippet    │     │  Results        │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available PHP Math Functions

| Category | Functions |
|----------|-----------|
| **Basic** | `abs`, `max`, `min`, `round`, `floor`, `ceil`, `fmod` |
| **Power** | `pow`, `sqrt`, `exp`, `log`, `log10` |
| **Trigonometry** | `sin`, `cos`, `tan`, `asin`, `acos`, `atan` |
| **Random** | `rand`, `mt_rand` (⚠️ use sparingly - not idempotent) |
| **Array** | `array_sum`, `array_product`, `count` |
| **Formatting** | `number_format`, `sprintf` |

---

## Example 1: Basic Statistics

Calculate mean, median, mode, and standard deviation:

```php
<?php
$values = $process_input['result']['values'] ?? [];

if (empty($values) || !is_array($values)) {
    return ['success' => false, 'error' => 'No numeric values provided'];
}

// Filter to numeric values only
$numbers = array_filter($values, 'is_numeric');
$numbers = array_map('floatval', $numbers);
$numbers = array_values($numbers);

if (empty($numbers)) {
    return ['success' => false, 'error' => 'No valid numeric values'];
}

$count = count($numbers);

// Mean (average)
$sum = array_sum($numbers);
$mean = $sum / $count;

// Median
sort($numbers);
$middle = floor($count / 2);
if ($count % 2 === 0) {
    $median = ($numbers[$middle - 1] + $numbers[$middle]) / 2;
} else {
    $median = $numbers[$middle];
}

// Mode (most frequent value)
$frequency = array_count_values(array_map('strval', $numbers));
arsort($frequency);
$maxFreq = max($frequency);
$modes = array_keys(array_filter($frequency, function($f) use ($maxFreq) {
    return $f === $maxFreq;
}));
$modes = array_map('floatval', $modes);

// Variance and Standard Deviation
$squaredDiffs = array_map(function($x) use ($mean) {
    return pow($x - $mean, 2);
}, $numbers);
$variance = array_sum($squaredDiffs) / $count;
$stdDev = sqrt($variance);

// Range
$min = min($numbers);
$max = max($numbers);
$range = $max - $min;

return [
    'success' => true,
    'data' => [
        'count' => $count,
        'sum' => round($sum, 4),
        'mean' => round($mean, 4),
        'median' => round($median, 4),
        'mode' => $modes,
        'mode_frequency' => $maxFreq,
        'variance' => round($variance, 4),
        'std_deviation' => round($stdDev, 4),
        'min' => $min,
        'max' => $max,
        'range' => $range
    ]
];
```

---

## Example 2: Percentile Calculation

Calculate percentiles and quartiles:

```php
<?php
$values = $process_input['result']['values'] ?? [];
$percentiles = $process_input['result']['percentiles'] ?? [25, 50, 75, 90, 95, 99];

$numbers = array_filter($values, 'is_numeric');
$numbers = array_map('floatval', $numbers);
sort($numbers);
$count = count($numbers);

if ($count === 0) {
    return ['success' => false, 'error' => 'No numeric values'];
}

// Calculate percentile function
$calcPercentile = function($data, $percentile) {
    $count = count($data);
    $index = ($percentile / 100) * ($count - 1);
    $lower = floor($index);
    $upper = ceil($index);
    
    if ($lower === $upper) {
        return $data[$lower];
    }
    
    $fraction = $index - $lower;
    return $data[$lower] + ($data[$upper] - $data[$lower]) * $fraction;
};

$results = [];
foreach ($percentiles as $p) {
    $results["p{$p}"] = round($calcPercentile($numbers, $p), 4);
}

// Quartiles
$q1 = $calcPercentile($numbers, 25);
$q2 = $calcPercentile($numbers, 50);
$q3 = $calcPercentile($numbers, 75);
$iqr = $q3 - $q1;

// Outlier boundaries (1.5 * IQR rule)
$lowerBound = $q1 - (1.5 * $iqr);
$upperBound = $q3 + (1.5 * $iqr);

$outliers = array_filter($numbers, function($x) use ($lowerBound, $upperBound) {
    return $x < $lowerBound || $x > $upperBound;
});

return [
    'success' => true,
    'data' => [
        'count' => $count,
        'percentiles' => $results,
        'quartiles' => [
            'q1' => round($q1, 4),
            'q2_median' => round($q2, 4),
            'q3' => round($q3, 4),
            'iqr' => round($iqr, 4)
        ],
        'outlier_detection' => [
            'lower_bound' => round($lowerBound, 4),
            'upper_bound' => round($upperBound, 4),
            'outliers' => array_values($outliers),
            'outlier_count' => count($outliers)
        ]
    ]
];
```

---

## Example 3: Financial Calculations

Common financial calculations:

```php
<?php
$input = $process_input['result'] ?? [];
$calculation = $input['calculation'] ?? 'compound_interest';

switch ($calculation) {
    case 'compound_interest':
        // A = P(1 + r/n)^(nt)
        $principal = floatval($input['principal'] ?? 0);
        $rate = floatval($input['annual_rate'] ?? 0) / 100;
        $compounds = intval($input['compounds_per_year'] ?? 12);
        $years = floatval($input['years'] ?? 1);
        
        $amount = $principal * pow(1 + ($rate / $compounds), $compounds * $years);
        $interest = $amount - $principal;
        
        $result = [
            'principal' => $principal,
            'annual_rate' => $rate * 100,
            'compounds_per_year' => $compounds,
            'years' => $years,
            'final_amount' => round($amount, 2),
            'total_interest' => round($interest, 2),
            'effective_rate' => round((pow(1 + $rate/$compounds, $compounds) - 1) * 100, 4)
        ];
        break;
        
    case 'loan_payment':
        // Monthly payment = P * [r(1+r)^n] / [(1+r)^n - 1]
        $principal = floatval($input['principal'] ?? 0);
        $annualRate = floatval($input['annual_rate'] ?? 0) / 100;
        $monthlyRate = $annualRate / 12;
        $months = intval($input['months'] ?? 12);
        
        if ($monthlyRate > 0) {
            $payment = $principal * ($monthlyRate * pow(1 + $monthlyRate, $months)) / 
                       (pow(1 + $monthlyRate, $months) - 1);
        } else {
            $payment = $principal / $months;
        }
        
        $totalPayment = $payment * $months;
        $totalInterest = $totalPayment - $principal;
        
        $result = [
            'principal' => $principal,
            'annual_rate' => $annualRate * 100,
            'term_months' => $months,
            'monthly_payment' => round($payment, 2),
            'total_payment' => round($totalPayment, 2),
            'total_interest' => round($totalInterest, 2)
        ];
        break;
        
    case 'roi':
        // ROI = (Gain - Cost) / Cost * 100
        $gain = floatval($input['gain'] ?? 0);
        $cost = floatval($input['cost'] ?? 0);
        
        if ($cost == 0) {
            return ['success' => false, 'error' => 'Cost cannot be zero'];
        }
        
        $roi = (($gain - $cost) / $cost) * 100;
        
        $result = [
            'gain' => $gain,
            'cost' => $cost,
            'profit' => $gain - $cost,
            'roi_percent' => round($roi, 2)
        ];
        break;
        
    case 'present_value':
        // PV = FV / (1 + r)^n
        $futureValue = floatval($input['future_value'] ?? 0);
        $rate = floatval($input['discount_rate'] ?? 0) / 100;
        $periods = intval($input['periods'] ?? 1);
        
        $presentValue = $futureValue / pow(1 + $rate, $periods);
        
        $result = [
            'future_value' => $futureValue,
            'discount_rate' => $rate * 100,
            'periods' => $periods,
            'present_value' => round($presentValue, 2)
        ];
        break;
        
    default:
        return ['success' => false, 'error' => 'Unknown calculation type'];
}

return [
    'success' => true,
    'data' => [
        'calculation' => $calculation,
        'result' => $result
    ]
];
```

---

## Example 4: Percentage Calculations

Various percentage operations:

```php
<?php
$input = $process_input['result'] ?? [];

$operations = [];

// Percentage of value
if (isset($input['value']) && isset($input['percent'])) {
    $value = floatval($input['value']);
    $percent = floatval($input['percent']);
    $operations['percent_of_value'] = [
        'value' => $value,
        'percent' => $percent,
        'result' => round(($value * $percent) / 100, 4)
    ];
}

// What percent is X of Y
if (isset($input['part']) && isset($input['whole'])) {
    $part = floatval($input['part']);
    $whole = floatval($input['whole']);
    if ($whole != 0) {
        $operations['part_of_whole'] = [
            'part' => $part,
            'whole' => $whole,
            'percent' => round(($part / $whole) * 100, 2)
        ];
    }
}

// Percentage change
if (isset($input['old_value']) && isset($input['new_value'])) {
    $oldValue = floatval($input['old_value']);
    $newValue = floatval($input['new_value']);
    if ($oldValue != 0) {
        $change = (($newValue - $oldValue) / abs($oldValue)) * 100;
        $operations['percentage_change'] = [
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'absolute_change' => $newValue - $oldValue,
            'percent_change' => round($change, 2),
            'direction' => $change >= 0 ? 'increase' : 'decrease'
        ];
    }
}

// Markup and margin
if (isset($input['cost']) && isset($input['price'])) {
    $cost = floatval($input['cost']);
    $price = floatval($input['price']);
    
    if ($cost > 0 && $price > 0) {
        $markup = (($price - $cost) / $cost) * 100;
        $margin = (($price - $cost) / $price) * 100;
        
        $operations['pricing'] = [
            'cost' => $cost,
            'price' => $price,
            'profit' => $price - $cost,
            'markup_percent' => round($markup, 2),
            'margin_percent' => round($margin, 2)
        ];
    }
}

return [
    'success' => true,
    'data' => $operations
];
```

---

## Example 5: Running Totals and Moving Averages

Calculate running statistics:

```php
<?php
$values = $process_input['result']['values'] ?? [];
$windowSize = (int)($process_input['result']['window_size'] ?? 3);

if (empty($values)) {
    return ['success' => false, 'error' => 'No values provided'];
}

$numbers = array_map('floatval', array_filter($values, 'is_numeric'));
$count = count($numbers);

// Running totals
$runningTotals = [];
$runningTotal = 0;
foreach ($numbers as $i => $value) {
    $runningTotal += $value;
    $runningTotals[] = [
        'index' => $i,
        'value' => $value,
        'running_total' => round($runningTotal, 4),
        'running_average' => round($runningTotal / ($i + 1), 4)
    ];
}

// Simple Moving Average (SMA)
$sma = [];
for ($i = 0; $i < $count; $i++) {
    if ($i < $windowSize - 1) {
        $sma[] = null; // Not enough data points
    } else {
        $window = array_slice($numbers, $i - $windowSize + 1, $windowSize);
        $sma[] = round(array_sum($window) / $windowSize, 4);
    }
}

// Exponential Moving Average (EMA)
$multiplier = 2 / ($windowSize + 1);
$ema = [];
$ema[0] = $numbers[0];

for ($i = 1; $i < $count; $i++) {
    $ema[$i] = round(($numbers[$i] * $multiplier) + ($ema[$i - 1] * (1 - $multiplier)), 4);
}

return [
    'success' => true,
    'data' => [
        'input_count' => $count,
        'window_size' => $windowSize,
        'final_total' => $runningTotal,
        'final_average' => round($runningTotal / $count, 4),
        'running_totals' => $runningTotals,
        'moving_averages' => [
            'sma' => $sma,
            'ema' => $ema
        ]
    ]
];
```

---

## Example 6: Distribution Analysis

Analyze data distribution:

```php
<?php
$values = $process_input['result']['values'] ?? [];
$bins = (int)($process_input['result']['bins'] ?? 10);

$numbers = array_filter($values, 'is_numeric');
$numbers = array_map('floatval', $numbers);
$count = count($numbers);

if ($count < 2) {
    return ['success' => false, 'error' => 'Need at least 2 values'];
}

$min = min($numbers);
$max = max($numbers);
$range = $max - $min;
$binWidth = $range / $bins;

// Create histogram
$histogram = [];
for ($i = 0; $i < $bins; $i++) {
    $binStart = $min + ($i * $binWidth);
    $binEnd = $binStart + $binWidth;
    $histogram[$i] = [
        'bin' => $i + 1,
        'range' => sprintf('%.2f - %.2f', $binStart, $binEnd),
        'start' => round($binStart, 4),
        'end' => round($binEnd, 4),
        'count' => 0,
        'frequency' => 0
    ];
}

// Populate histogram
foreach ($numbers as $num) {
    $binIndex = min($bins - 1, floor(($num - $min) / $binWidth));
    $histogram[$binIndex]['count']++;
}

// Calculate frequencies
foreach ($histogram as &$bin) {
    $bin['frequency'] = round(($bin['count'] / $count) * 100, 2);
}

// Calculate skewness
$mean = array_sum($numbers) / $count;
$m3 = array_sum(array_map(function($x) use ($mean) {
    return pow($x - $mean, 3);
}, $numbers)) / $count;
$m2 = array_sum(array_map(function($x) use ($mean) {
    return pow($x - $mean, 2);
}, $numbers)) / $count;
$skewness = ($m2 > 0) ? $m3 / pow($m2, 1.5) : 0;

// Determine distribution shape
$shape = 'symmetric';
if ($skewness < -0.5) {
    $shape = 'left-skewed (negative)';
} elseif ($skewness > 0.5) {
    $shape = 'right-skewed (positive)';
}

return [
    'success' => true,
    'data' => [
        'count' => $count,
        'min' => $min,
        'max' => $max,
        'range' => $range,
        'bin_width' => round($binWidth, 4),
        'histogram' => array_values($histogram),
        'skewness' => round($skewness, 4),
        'distribution_shape' => $shape
    ]
];
```

---

## Example 7: Weighted Calculations

Perform weighted averages and scores:

```php
<?php
$items = $process_input['result']['items'] ?? [];

if (empty($items)) {
    return ['success' => false, 'error' => 'No items provided'];
}

// Calculate weighted average
$totalWeight = 0;
$weightedSum = 0;
$processed = [];

foreach ($items as $item) {
    $value = floatval($item['value'] ?? 0);
    $weight = floatval($item['weight'] ?? 1);
    
    $weightedSum += $value * $weight;
    $totalWeight += $weight;
    
    $processed[] = [
        'label' => $item['label'] ?? 'Item',
        'value' => $value,
        'weight' => $weight,
        'contribution' => round($value * $weight, 4)
    ];
}

$weightedAverage = ($totalWeight > 0) ? $weightedSum / $totalWeight : 0;

// Calculate simple average for comparison
$simpleSum = array_sum(array_column($processed, 'value'));
$simpleAverage = $simpleSum / count($processed);

// Calculate each item's percentage contribution
foreach ($processed as &$item) {
    $item['percent_of_total'] = ($totalWeight > 0) 
        ? round(($item['weight'] / $totalWeight) * 100, 2) 
        : 0;
}

return [
    'success' => true,
    'data' => [
        'items' => $processed,
        'total_weight' => $totalWeight,
        'weighted_sum' => round($weightedSum, 4),
        'weighted_average' => round($weightedAverage, 4),
        'simple_average' => round($simpleAverage, 4),
        'difference' => round($weightedAverage - $simpleAverage, 4)
    ]
];
```

---

## Example 8: Rounding and Formatting

Various rounding and number formatting options:

```php
<?php
$number = floatval($process_input['result']['number'] ?? 0);
$decimals = (int)($process_input['result']['decimals'] ?? 2);
$currency = $process_input['result']['currency'] ?? 'USD';

// Different rounding methods
$rounding = [
    'original' => $number,
    'round' => round($number, $decimals),
    'floor' => floor($number * pow(10, $decimals)) / pow(10, $decimals),
    'ceil' => ceil($number * pow(10, $decimals)) / pow(10, $decimals),
    'truncate' => intval($number * pow(10, $decimals)) / pow(10, $decimals)
];

// Banker's rounding (round half to even)
$bankersRound = function($num, $dec) {
    $factor = pow(10, $dec);
    $shifted = $num * $factor;
    $fractional = $shifted - floor($shifted);
    
    if ($fractional == 0.5) {
        // Round to nearest even
        $base = floor($shifted);
        return ($base % 2 === 0 ? $base : $base + 1) / $factor;
    }
    return round($num, $dec);
};
$rounding['bankers_round'] = $bankersRound($number, $decimals);

// Formatting
$currencySymbols = [
    'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'CHF' => 'CHF '
];
$symbol = $currencySymbols[$currency] ?? '';

$formatting = [
    'number_format' => number_format($number, $decimals),
    'with_thousands' => number_format($number, $decimals, '.', ','),
    'european' => number_format($number, $decimals, ',', ' '),
    'currency' => $symbol . number_format($number, $decimals),
    'percentage' => number_format($number * 100, $decimals) . '%',
    'scientific' => sprintf('%.' . $decimals . 'e', $number),
    'padded' => sprintf('%010.' . $decimals . 'f', $number)
];

// Significant figures
$sigFigs = function($num, $n) {
    if ($num == 0) return 0;
    $d = ceil(log10(abs($num)));
    $power = $n - (int)$d;
    $magnitude = pow(10, $power);
    return round($num * $magnitude) / $magnitude;
};
$formatting['sig_figs_3'] = $sigFigs($number, 3);

return [
    'success' => true,
    'data' => [
        'input' => $number,
        'decimals' => $decimals,
        'rounding' => $rounding,
        'formatting' => $formatting
    ]
];
```

---

## Example 9: Correlation Calculation

Calculate correlation between two data sets:

```php
<?php
$xValues = $process_input['result']['x'] ?? [];
$yValues = $process_input['result']['y'] ?? [];

// Validate inputs
$x = array_map('floatval', array_filter($xValues, 'is_numeric'));
$y = array_map('floatval', array_filter($yValues, 'is_numeric'));

$n = min(count($x), count($y));
if ($n < 2) {
    return ['success' => false, 'error' => 'Need at least 2 paired values'];
}

// Ensure same length
$x = array_slice($x, 0, $n);
$y = array_slice($y, 0, $n);

// Calculate means
$meanX = array_sum($x) / $n;
$meanY = array_sum($y) / $n;

// Calculate covariance and variances
$covariance = 0;
$varX = 0;
$varY = 0;

for ($i = 0; $i < $n; $i++) {
    $dx = $x[$i] - $meanX;
    $dy = $y[$i] - $meanY;
    $covariance += $dx * $dy;
    $varX += $dx * $dx;
    $varY += $dy * $dy;
}

$covariance /= $n;
$varX /= $n;
$varY /= $n;

// Pearson correlation coefficient
$stdX = sqrt($varX);
$stdY = sqrt($varY);
$correlation = ($stdX * $stdY > 0) ? $covariance / ($stdX * $stdY) : 0;

// Simple linear regression (y = mx + b)
$slope = ($varX > 0) ? $covariance / $varX : 0;
$intercept = $meanY - ($slope * $meanX);

// R-squared
$rSquared = $correlation * $correlation;

// Interpret correlation
$interpretation = 'no correlation';
$absCorr = abs($correlation);
if ($absCorr >= 0.9) $interpretation = 'very strong';
elseif ($absCorr >= 0.7) $interpretation = 'strong';
elseif ($absCorr >= 0.5) $interpretation = 'moderate';
elseif ($absCorr >= 0.3) $interpretation = 'weak';
elseif ($absCorr > 0) $interpretation = 'very weak';

if ($correlation < 0 && $absCorr > 0.1) {
    $interpretation .= ' negative';
} elseif ($correlation > 0 && $absCorr > 0.1) {
    $interpretation .= ' positive';
}

return [
    'success' => true,
    'data' => [
        'n' => $n,
        'correlation' => round($correlation, 4),
        'interpretation' => $interpretation,
        'r_squared' => round($rSquared, 4),
        'covariance' => round($covariance, 4),
        'regression' => [
            'slope' => round($slope, 4),
            'intercept' => round($intercept, 4),
            'equation' => sprintf('y = %.4fx + %.4f', $slope, $intercept)
        ],
        'statistics' => [
            'x_mean' => round($meanX, 4),
            'y_mean' => round($meanY, 4),
            'x_std' => round($stdX, 4),
            'y_std' => round($stdY, 4)
        ]
    ]
];
```

---

## Example 10: Unit Conversion

Convert between different units:

```php
<?php
$value = floatval($process_input['result']['value'] ?? 0);
$fromUnit = strtolower($process_input['result']['from_unit'] ?? '');
$toUnit = strtolower($process_input['result']['to_unit'] ?? '');
$category = strtolower($process_input['result']['category'] ?? 'length');

// Conversion factors (to base unit)
$conversions = [
    'length' => [ // base: meters
        'm' => 1, 'km' => 1000, 'cm' => 0.01, 'mm' => 0.001,
        'mi' => 1609.344, 'yd' => 0.9144, 'ft' => 0.3048, 'in' => 0.0254
    ],
    'weight' => [ // base: kilograms
        'kg' => 1, 'g' => 0.001, 'mg' => 0.000001, 'lb' => 0.453592, 'oz' => 0.0283495
    ],
    'volume' => [ // base: liters
        'l' => 1, 'ml' => 0.001, 'gal' => 3.78541, 'qt' => 0.946353, 'pt' => 0.473176, 'cup' => 0.236588
    ],
    'temperature' => [ // special handling
        'c' => 'celsius', 'f' => 'fahrenheit', 'k' => 'kelvin'
    ],
    'data' => [ // base: bytes
        'b' => 1, 'kb' => 1024, 'mb' => 1048576, 'gb' => 1073741824, 'tb' => 1099511627776
    ]
];

if (!isset($conversions[$category])) {
    return ['success' => false, 'error' => 'Unknown category: ' . $category];
}

$units = $conversions[$category];

// Temperature conversion (special case)
if ($category === 'temperature') {
    $result = $value;
    
    // Convert to Celsius first
    switch ($fromUnit) {
        case 'f': $result = ($value - 32) * 5/9; break;
        case 'k': $result = $value - 273.15; break;
    }
    
    // Convert from Celsius to target
    switch ($toUnit) {
        case 'f': $result = ($result * 9/5) + 32; break;
        case 'k': $result = $result + 273.15; break;
    }
    
    return [
        'success' => true,
        'data' => [
            'value' => $value,
            'from_unit' => $fromUnit,
            'to_unit' => $toUnit,
            'result' => round($result, 4),
            'category' => $category
        ]
    ];
}

// Standard conversion
if (!isset($units[$fromUnit]) || !isset($units[$toUnit])) {
    return ['success' => false, 'error' => 'Unknown unit'];
}

$baseValue = $value * $units[$fromUnit];
$result = $baseValue / $units[$toUnit];

return [
    'success' => true,
    'data' => [
        'value' => $value,
        'from_unit' => $fromUnit,
        'to_unit' => $toUnit,
        'result' => round($result, 6),
        'category' => $category,
        'formula' => sprintf('%g %s = %g %s', $value, $fromUnit, round($result, 6), $toUnit)
    ]
];
```

---

## Best Practices

### 1. Handle Division by Zero
```php
if ($divisor == 0) {
    return ['success' => false, 'error' => 'Division by zero'];
}
```

### 2. Use Appropriate Precision
```php
// Financial calculations - 2 decimal places
$amount = round($value, 2);

// Scientific calculations - more precision
$result = round($value, 6);
```

### 3. Validate Numeric Input
```php
$input = $process_input['result'] ?? $process_input;
$numbers = array_filter($input, 'is_numeric');
if (empty($numbers)) {
    return ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'No valid numbers'], 'data' => null];
}
```

### 4. Consider Floating Point Precision
```php
// Don't compare floats with ==
if (abs($a - $b) < 0.0001) {
    // Values are approximately equal
}
```

---

## See Also

- [Data Transformation & Mapping](Data_Transformation_Mapping.md)
- [Array & Collection Operations](Array_Collection_Operations.md)
- [Date & Time Operations](Date_Time_Operations.md)
