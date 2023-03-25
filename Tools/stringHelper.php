#!/usr/bin/php
<?php

include_once 'PhpLcs/LcsSolver.php';

$action = $argv[1];

switch ($action) {
    case 'digest-diff':
        digestDiff($argv[2], $argv[3]);
        break;

    case 'translate':
        translate($argv[2], $argv[3], $argv[4] ?? null);
        break;

    case 'apply-changes':
        applyChanges($argv[2], $argv[3], $argv[4]);
        break;
}

function digestDiff($diffFile, $outputFile) {
    
    if (file_exists($outputFile)) {
        $existingConfig = include $outputFile;
    }
    else {
        $existingConfig = [];
    }
    
    $diff = file_get_contents($diffFile);
    
    $diffArray = [];
    if ($file = fopen($diffFile, "r")) {
        while(!feof($file)) {
            $line = fgets($file);
            // echo $line . PHP_EOL;
            
            if ($line && $line[1] == ' ') {
                switch ($line[0]) {
                    case '-':
                        list($name, $string) = getNameAndString($line);
                        if ($name) {
                            $diffArray[$name] = ['before' => $string];
                        }
                        break;
    
                    case '+':
                        list($name, $string) = getNameAndString($line);
                        if ($name && isset($diffArray[$name])) {
                            $diffArray[$name]['after'] = $string;
                        }
                        break;
                }
            }
            
        }
        fclose($file);
    }
    
    foreach ($diffArray as $key => $changedLine) {
        if (!($existingConfig[$key]['automatic_updates'] ?? true)) continue;

        $existingConfig[$key] = findChanges($changedLine['before'], $changedLine['after']);
    }
    
    file_put_contents($outputFile, '<?php' . PHP_EOL . 'return ' . export($existingConfig) . ';');
}

function translate($inputFile, $outputFile, $targetFile = null)
{
    if (!file_exists($inputFile)) {
        echo 'Input file not found.';
        exit;
    }
    $input = include $inputFile;


    if (file_exists($outputFile)) {
        $output = include $outputFile;
    }
    else {
        $output = [];
    }

    $target = [];
    if ($targetFile && file_exists($targetFile)) {
        $targetDom = new DOMDocument();
        $targetDom->load($targetFile);
    
        $targetList = $targetDom->getElementsByTagName('string');

        foreach ($targetList as $targetNode) {
            /** @var DOMNode $targetNode */
            $nameAttr = $targetNode->attributes->getNamedItem('name');
            if (!$nameAttr) continue;
    
            $target[$nameAttr->nodeValue] = $targetNode->nodeValue;
        }
    }

    $cache = [];
    foreach ($input as $key => $changes) {
        if (!($output[$key]['automatic_updates'] ?? true)) continue;

        if ($changes['translation_needed'] ?? false) {
            $result = [];
            foreach ($changes['before'] as $before) {
                $after = current($changes['after']);

                if (isset($cache[$after])) {
                    $line = $cache[$after];
                }
                else {
                    if (isset($target[$key])) echo '«' . $target[$key] . '»' . PHP_EOL;
                    echo 'Before:' . PHP_EOL;
                    echo $before . PHP_EOL;
                    echo 'After:' . PHP_EOL;
                    echo $after . PHP_EOL;
                    echo 'Please, input translation: ';
                    $line = trim(fgets(STDIN)); // reads one line from STDIN
                    $cache[$after] = $line;
                }

                $result[] = $line;
                next($changes['after']);
            }
            $changes['after'] = $result;
        }
        $output[$key] = $changes;
    }

    file_put_contents($outputFile, '<?php' . PHP_EOL . 'return ' . export($output) . ';');
}

function applyChanges($changesFile, $referenceFile, $targetFile)
{
    if (!file_exists($changesFile)) {
        echo 'Changes file not found.';
        exit;
    }
    if (!file_exists($referenceFile)) {
        echo 'Reference file not found.';
        exit;
    }
    if (!file_exists($targetFile)) {
        echo 'Target file not found.';
        exit;
    }

    $changes = require $changesFile;

    $targetDom = new DOMDocument();
    $targetDom->load($targetFile);

    $targetList = $targetDom->getElementsByTagName('string');

    /** @var DOMNode[] $targetMap */
    $targetMap = [];
    foreach ($targetList as $targetNode) {
        /** @var DOMNode $targetNode */
        $nameAttr = $targetNode->attributes->getNamedItem('name');
        if (!$nameAttr) continue;

        $targetMap[$nameAttr->nodeValue] = $targetNode;
    }

    $referenceDom = new DOMDocument();
    $referenceDom->load($referenceFile);

    $referenceList = $referenceDom->getElementsByTagName('string');

    foreach ($referenceList as $referenceNode) {
        /** @var DOMNode $referenceNode */
        $nameAttr = $referenceNode->attributes->getNamedItem('name');
        if (!$nameAttr) continue;
        $name = $nameAttr->nodeValue;

        // Target exists
        if (isset($targetMap[$name])) {
            // Changes are known
            if (isset($changes[$name])) {
                $currentChanges = &$changes[$name];
                foreach ($currentChanges['before'] as &$before) {
                    if ($currentChanges['translation_needed'] ?? false) {
                        echo 'Search string (' . $before . '): ';
                        $line = trim(fgets(STDIN)); // reads one line from STDIN
                        if ($line) {
                            $before = $line;
                        }
                    }
                    $after = current($currentChanges['after']);

                    // TODO: Escape reg-ex characters
                    // [
                    // ]
                    // \
                    // /
                    // ^
                    // *
                    // +
                    // ?
                    // {
                    // }
                    // |
                    // (
                    // )
                    // $
                    // .
                    $targetMap[$name]->nodeValue = preg_replace('/' . $before . '/', $after, $targetMap[$name]->nodeValue, 1);
                    next($currentChanges['after']);
                }
                echo $targetMap[$name]->nodeValue . PHP_EOL;
            }
        }
        // Target doesn't exist
        else {
            echo 'Original:' . PHP_EOL;
            echo $referenceNode->nodeValue . PHP_EOL;
            echo 'Translation:' . PHP_EOL;
            $line = trim(fgets(STDIN)); // reads one line from STDIN

            // Create the new node
            $newNode = $targetDom->createElement('string', $line);
            $newNameAttr = $targetDom->createAttribute('name');
            $newNameAttr->value = $name;

            // Try to insert in the right position
            $currentNode = $referenceNode;
            while(true) {
                $previousNode = $currentNode->previousSibling;
                if ($previousNode === null) {
                    $firstNode = $targetList->item(0);
                    $firstNode->parentNode->insertBefore($newNode, $firstNode);
                    $targetMap[$name] = $newNode;
                    break;
                }

                if ($previousNode->hasAttributes() && ($prevNameAttr = $previousNode->attributes->getNamedItem('name')) && isset($targetMap[$prevNameAttr->nodeValue])) {
                    /** @var DOMNode[] $targetMap */
                    $targetMap[$prevNameAttr->nodeValue]->parentNode->insertBefore($newNode, $targetMap[$prevNameAttr->nodeValue]->nextSibling);
                    break;
                }
                $currentNode = $previousNode;
            }
        }
    }

    $targetDom->save($targetFile);
    file_put_contents($changesFile, '<?php' . PHP_EOL . 'return ' . export($changes) . ';');
}

function getNameAndString($line)
{
    $dom = new DOMDocument();
    $dom->loadXML(substr($line, 1));

    $list = $dom->getElementsByTagName('string');
    
    if ($list->length > 0) {
        $el = $list->item(0);

        $nameAttr = $el->attributes->getNamedItem('name');
        if ($nameAttr) return [$nameAttr->nodeValue, $el->nodeValue];
    }
    return [null, null];
}

function findChanges($a, $b)
{
    $solver = new \Eloquent\Lcs\LcsSolver();
    $sequenceA = explode(' ', $a);
    $sequenceB = explode(' ', $b);

    $lcs = $solver->longestCommonSubsequence($sequenceA, $sequenceB);


    return ['before' => lcsDifference($sequenceA, $lcs), 'after' => lcsDifference($sequenceB, $lcs)];
}

function lcsDifference($sequence, $lcs)
{
    $diff = [];
    $justDiff = false;
    reset($lcs);
    foreach ($sequence as $token) {
        if ($token === current($lcs)) {
            $justDiff = false;
            next($lcs);
        }
        else {
            if ($justDiff) {
                $diff[count($diff) - 1] .= ' ' . $token;
            }
            else {
                $diff[] = $token;
            }
            $justDiff = true;
        }
    }
    return $diff;
}

/**
 * Export variables using shor array notation
 */
function export($var, $indent="")
{
    switch (gettype($var)) {
        case "string":
            return '"' . addcslashes($var, "\\\$\"\r\n\t\v\f") . '"';
        case "array":
            $indexed = array_keys($var) === range(0, count($var) - 1);
            $r = [];
            foreach ($var as $key => $value) {
                $r[] = "$indent    "
                    . ($indexed ? "" : export($key) . " => ")
                    . export($value, "$indent    ");
            }
            return "[\n" . implode(",\n", $r) . "\n" . $indent . "]";
        case "boolean":
            return $var ? "TRUE" : "FALSE";
        default:
            return var_export($var, TRUE);
    }
}
