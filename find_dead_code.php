<?php

$dir = new RecursiveDirectoryIterator('c:/gis/app/www');
$ite = new RecursiveIteratorIterator($dir);
$files = new RegexIterator($ite, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

$stringCounts = [];
$definitions = [];

foreach($files as $file) {
    if (strpos($file[0], 'find_dead_code.php') !== false) continue;
    $content = file_get_contents($file[0]);
    $tokens = token_get_all($content);
    
    $inClass = false;
    $currentClass = '';

    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        if (is_array($token)) {
            if ($token[0] === T_CLASS) {
                $j = $i + 1;
                while($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
                if ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $currentClass = $tokens[$j][1];
                    $definitions['class'][$currentClass] = $file[0];
                }
            }

            if ($token[0] === T_FUNCTION) {
                $j = $i + 1;
                while($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
                if ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $funcName = $tokens[$j][1];
                    // Skip magic methods
                    if (strpos($funcName, '__') !== 0) {
                        if ($inClass) {
                            $definitions['method'][$funcName] = $file[0];
                        } else {
                            $definitions['function'][$funcName] = $file[0];
                        }
                    }
                }
            }

            if ($token[0] === T_STRING) {
                $name = $token[1];
                if (!isset($stringCounts[$name])) {
                    $stringCounts[$name] = 0;
                }
                $stringCounts[$name]++;
            }
        } else if ($token === '{') {
            // simple scope tracking, not perfect but okay
        }
    }
}

echo "DEAD CODE REPORT\n";
echo "================\n\n";

$found = 0;
foreach (['function', 'method', 'class'] as $type) {
    if (!isset($definitions[$type])) continue;
    
    // For methods and functions, if count == 1, it's only defined, never used.
    // (Assuming definition creates exactly 1 T_STRING, and no calls exist)
    // For classes as well.
    echo "Unused {$type}s:\n";
    echo str_repeat('-', strlen($type) + 9) . "\n";
    $typeFound = false;
    foreach ($definitions[$type] as $name => $file) {
        if (isset($stringCounts[$name]) && $stringCounts[$name] === 1) {
            echo "- " . $name . " in " . realpath($file) . "\n";
            $typeFound = true;
            $found++;
        }
    }
    if (!$typeFound) echo "None found.\n";
    echo "\n";
}

if ($found === 0) {
    echo "No dead code found.\n";
}
