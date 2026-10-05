<?php

/**
 * CLI MariaDB Dump
 *
 * php mariadb-dump.php -h 127.0.0.1 -u root -p root -d dbname -c utf8mb4_uca1400_ai_ci
 *
 */

date_default_timezone_set('Asia/Makassar');


// OPTIONS
$options = getopt("h:u:p:d:r:c:g:");
$host = $options['h'] ?? '';
$user = $options['u'] ?? '';
$pass = $options['p'] ?? '';
$dbname = $options['d'] ?? '';
$collation = $options['c'] ?? 'utf8mb4_uca1400_ai_ci';
$record = $options['r'] ?? 'y';
$gzip = $options['g'] ?? 'n';

if(empty($host)) {
    echo 'No Host Specified';
    exit();
}

if(empty($user)) {
    echo 'No User Specified';
    exit();
}

if(empty($dbname)) {
    echo 'No Database Specified';
    exit();
}

$dir = '/home/dump';

if (PHP_OS_FAMILY == 'Windows')
{
    $dir = 'c:\users\dump';
}


$charset = 'utf8mb4';
$timestamp = date("Ymd");
$filename = $dbname . "_" . $timestamp . ".sql";
if($gzip == 'y') $filename = $dbname . "_" . $timestamp . ".sql.gz";

$fullPath = $dir . DIRECTORY_SEPARATOR . $filename;
$maxBatchRows = 100000;

echo "\nGZip: ".$gzip."\n";
echo "\nHost: ".$host."\n";
echo "Database: ".$dbname."\n";
echo "Collation: ".$collation."\n\n";

if(php_sapi_name() !== 'cli') die("Error: CLI only.");

if($gzip == 'y') if(!function_exists('gzopen')) die("Error: zlib extension missing.\n");

set_time_limit(0);
ini_set('memory_limit', '1024M');

if(!is_dir($dir)) mkdir($dir, 0755, true);

if(is_file($fullPath)) unlink ($fullPath);

if($gzip == 'y')
{
	$fileHandle = gzopen($fullPath, 'w9');
}
else
{
	$fileHandle = fopen($fullPath, 'w');
}

if (!$fileHandle) {
    die("Error: Could not open file $fullPath for writing.\n");
}

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES $charset"
    ]);

    $maxPacket = $pdo->query("SELECT @@max_allowed_packet")->fetchColumn();
    $safeLimit = $maxPacket * 0.85;

    echo "Starting Plain SQL Backup...\n";
	
	if($gzip == 'y')
	{
		gzwrite($fileHandle, "-- Database Dump: $dbname\n");
		gzwrite($fileHandle, "SET NAMES $charset COLLATE $collation;\n");
		gzwrite($fileHandle, "SET time_zone = '+00:00';\n");
		gzwrite($fileHandle, "SET foreign_key_checks=0;\n");
		gzwrite($fileHandle, "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
		gzwrite($fileHandle, "DROP DATABASE IF EXISTS `$dbname`;\n");
		gzwrite($fileHandle, "CREATE DATABASE `$dbname`;\n");
		gzwrite($fileHandle, "USE `$dbname`;\n");
	}
	else
	{
		fwrite($fileHandle, "-- Database Dump: $dbname\n");
		fwrite($fileHandle, "SET NAMES $charset COLLATE $collation;\n");
		fwrite($fileHandle, "SET time_zone = '+00:00';\n");
		fwrite($fileHandle, "SET foreign_key_checks=0;\n");
		fwrite($fileHandle, "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
		fwrite($fileHandle, "DROP DATABASE IF EXISTS `$dbname`;\n");
		fwrite($fileHandle, "CREATE DATABASE `$dbname`;\n");
		fwrite($fileHandle, "USE `$dbname`;\n");
	}

    $foreignKeys = [];

    // --- TABLES ---
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table)
    {
        echo "Create Table: $table... ";

        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $createSQL = $create['Create Table'];

        $pattern = '/CONSTRAINT\s+`?([^`]+)`?\s+FOREIGN KEY\s+\((.*?)\)\s+REFERENCES\s+((?:`?[^`\.\s]+`?\.)?`?[^`\s()]+`?)\s+\((.*?)\)(.*?)($|,)/ms';

        if (preg_match_all($pattern, $createSQL, $matches, PREG_SET_ORDER))
        {
            foreach ($matches as $match)
            {
                $parts = explode('.', str_replace('`', '', $match[3]));
                $referencedTable = implode('`.`', $parts);
                $foreignKeys[] = "ALTER TABLE `$table` ADD CONSTRAINT `{$match[1]}` FOREIGN KEY ({$match[2]}) REFERENCES `{$referencedTable}` ({$match[4]}) " . trim($match[5]) . ";";
            }
            $createSQL = preg_replace($pattern, '', $createSQL);
            $createSQL = preg_replace('/,\s*[\r\n]+\)/', "\n)", $createSQL);
        }

        $createSQL = preg_replace('/AUTO_INCREMENT=\d+/', '', $createSQL);

        if($collation == 'utf8mb4_uca1400_ai_ci') {
            $createSQL = preg_replace('/utf8_general_ci/', $collation, $createSQL);
			$createSQL = preg_replace('/utf8_unicode_ci/', $collation, $createSQL);
            $createSQL = preg_replace('/utf8mb3_general_ci/', $collation, $createSQL);
            $createSQL = preg_replace('/utf8mb4_general_ci/', $collation, $createSQL);
            $createSQL = preg_replace('/utf8mb4_0900_ai_ci/', $collation, $createSQL);
            $createSQL = preg_replace('/utf8mb3_unicode_ci/', $collation, $createSQL);
        }

        if($collation == 'utf8mb4_general_ci') {
            $createSQL = preg_replace('/utf8_general_ci/', $collation, $createSQL);
			$createSQL = preg_replace('/utf8_unicode_ci/', $collation, $createSQL);
            $createSQL = preg_replace('/utf8mb3_general_ci/', $collation, $createSQL);
            $createSQL = preg_replace('/utf8mb4_0900_ai_ci/', $collation, $createSQL);
            $createSQL = preg_replace('/utf8mb4_uca1400_ai_ci/', $collation, $createSQL);
            $createSQL = preg_replace('/utf8mb3_unicode_ci/', $collation, $createSQL);
        }

        $createSQL = preg_replace('/SET utf8 /', 'SET utf8mb4 ', $createSQL);
		
		if($gzip == 'y')
		{
			gzwrite($fileHandle, "DROP TABLE IF EXISTS `$table`;\n$createSQL;\n\n");
		}
		else
		{
			fwrite($fileHandle, "DROP TABLE IF EXISTS `$table`;\n$createSQL;\n\n");
		}

        echo "Done.\n";
    }

    // --- VIEWS ---
    $views = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'VIEW'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($views as $view)
    {
        echo "Processing View: $view... ";
		
		if($gzip == 'y')
		{
			gzwrite($fileHandle, "DROP VIEW IF EXISTS `$view`;\n");
		}
		else
		{
			fwrite($fileHandle, "DROP VIEW IF EXISTS `$view`;\n");
		}
		
        $create = $pdo->query("SHOW CREATE VIEW `$view`")->fetch(PDO::FETCH_ASSOC);
        $createSQL = $create['Create View'];
        $createSQL = preg_replace('/DEFINER=`[^`]+`@`[^`]+`/', '', $createSQL);
		
		if($gzip == 'y')
		{
			gzwrite($fileHandle, $createSQL . ";\n\n");
		}
		else
		{
			fwrite($fileHandle, $createSQL . ";\n\n");
		}
		
        echo "Done.\n";
    }

    // --- ROUTINES ---
    foreach (['PROCEDURE', 'FUNCTION'] as $type)
    {
        $stmt = $pdo->query("SHOW $type STATUS WHERE Db = " . $pdo->quote($dbname));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
        {
            $name = $row['Name'];
            echo "Processing $type: $name... ";
			
			if($gzip == 'y')
			{
				gzwrite($fileHandle, "DROP $type IF EXISTS `$name`;\nDELIMITER //\n");
			}
			else
			{
				fwrite($fileHandle, "DROP $type IF EXISTS `$name`;\nDELIMITER //\n");
			}
			
            $create = $pdo->query("SHOW CREATE $type `$name`")->fetch(PDO::FETCH_ASSOC);
            $key = (isset($create['Create Procedure'])) ? 'Create Procedure' : 'Create Function';
            $createSQL = $create[$key];
            $createSQL = preg_replace('/DEFINER=`[^`]+`@`[^`]+`/', '', $createSQL);

			if($collation == 'utf8mb4_uca1400_ai_ci') {
				$createSQL = preg_replace('/utf8_general_ci/', $collation, $createSQL);
				$createSQL = preg_replace('/utf8_unicode_ci/', $collation, $createSQL);
				$createSQL = preg_replace('/utf8mb3_general_ci/', $collation, $createSQL);
				$createSQL = preg_replace('/utf8mb4_general_ci/', $collation, $createSQL);
				$createSQL = preg_replace('/utf8mb4_0900_ai_ci/', $collation, $createSQL);
				$createSQL = preg_replace('/utf8mb3_unicode_ci/', $collation, $createSQL);
			}

			if($collation == 'utf8mb4_general_ci') {
				$createSQL = preg_replace('/utf8_general_ci/', $collation, $createSQL);
				$createSQL = preg_replace('/utf8_unicode_ci/', $collation, $createSQL);
				$createSQL = preg_replace('/utf8mb3_general_ci/', $collation, $createSQL);
				$createSQL = preg_replace('/utf8mb4_0900_ai_ci/', $collation, $createSQL);
				$createSQL = preg_replace('/utf8mb4_uca1400_ai_ci/', $collation, $createSQL);
				$createSQL = preg_replace('/utf8mb3_unicode_ci/', $collation, $createSQL);
			}

            $createSQL = preg_replace('/SET utf8 /', 'SET utf8mb4 ', $createSQL);
			
			if($gzip == 'y')
			{
				gzwrite($fileHandle, $createSQL . " //\nDELIMITER ;\n\n");
			}
			else
			{
				fwrite($fileHandle, $createSQL . " //\nDELIMITER ;\n\n");
			}
			
            echo "Done.\n";
        }
    }

    // --- FOREIGN KEYS ---
    if (!empty($foreignKeys))
    {
        echo "Processing Foreign Keys (" . count($foreignKeys) . " constraints)... ";
		
		if($gzip == 'y')
		{
			gzwrite($fileHandle, "\n-- Foreign Keys --\n");
		}
		else
		{
			fwrite($fileHandle, "\n-- Foreign Keys --\n");
		}
        
		foreach ($foreignKeys as $fkSql)
        {
			if($gzip == 'y')
			{
				gzwrite($fileHandle, $fkSql . "\n");
			}
			else
			{
				fwrite($fileHandle, $fkSql . "\n");
			}
        }
        echo "Done.\n";
    }

    if($record == 'y')
    {
        // --- TABLES DATA ---
        foreach ($tables as $table)
        {
            echo "Insert Table: $table... ";

            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

            $stmt = $pdo->query("SELECT * FROM `$table`");

            $stmt->setFetchMode(PDO::FETCH_NUM);

            $buffer = [];
            $currentSize = 0;

            foreach ($stmt as $row)
            {
                $vals = [];
                foreach ($row as $val)
                {
                    if ($val === null) $vals[] = "NULL";
                    else $vals[] = $pdo->quote($val);
                }
                $rowStr = "(" . implode(',', $vals) . ")";
                $rowLen = strlen($rowStr);

                if (!empty($buffer) && (count($buffer) >= $maxBatchRows || ($currentSize + $rowLen) > $safeLimit))
                {
					if($gzip == 'y')
					{
						gzwrite($fileHandle, "INSERT INTO `$table` VALUES " . implode(',', $buffer) . ";\n");
					}
					else
					{
						fwrite($fileHandle, "INSERT INTO `$table` VALUES " . implode(',', $buffer) . ";\n");
					}
					
                    $buffer = [];
                    $currentSize = 0;
                }
                $buffer[] = $rowStr;
                $currentSize += $rowLen;
            }
            if (!empty($buffer))
            {
				if($gzip == 'y')
				{
					gzwrite($fileHandle, "INSERT INTO `$table` VALUES " . implode(',', $buffer) . ";\n");
				}
				else
				{
					fwrite($fileHandle, "INSERT INTO `$table` VALUES " . implode(',', $buffer) . ";\n");
				}
            }
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            echo "Done.\n";
        }
    }

    // --- TRIGGERS ---
    echo "Processing Triggers... ";
    $triggers = $pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC);
    if ($triggers)
    {
        
		if($gzip == 'y')
		{
			gzwrite($fileHandle, "-- Triggers --\n");
		}
		else
		{
			fwrite($fileHandle, "-- Triggers --\n");
		}
		
        foreach ($triggers as $trig)
        {
            $name = $trig['Trigger'];

            $create = $pdo->query("SHOW CREATE TRIGGER `$name`")->fetch(PDO::FETCH_ASSOC);
            $createSQL = $create['SQL Original Statement'];
            $createSQL = preg_replace('/DEFINER=`[^`]+`@`[^`]+`/', '', $createSQL);
			
			if($gzip == 'y')
			{
				gzwrite($fileHandle, "DROP TRIGGER IF EXISTS `$name`;\n");
				gzwrite($fileHandle, "DELIMITER //\n");
				gzwrite($fileHandle, $createSQL . "//\n");
				gzwrite($fileHandle, "DELIMITER ;\n\n");
			}
			else
			{
				fwrite($fileHandle, "DROP TRIGGER IF EXISTS `$name`;\n");
				fwrite($fileHandle, "DELIMITER //\n");
				fwrite($fileHandle, $createSQL . "//\n");
				fwrite($fileHandle, "DELIMITER ;\n\n");
			}
        }
    }
    echo "Done.\n";
	
	if($gzip == 'y')
	{
		gzwrite($fileHandle, "ALTER DATABASE `$dbname` COLLATE $collation;\n");
		gzwrite($fileHandle, "SET FOREIGN_KEY_CHECKS=1;\n");
	}
	else
	{
		fwrite($fileHandle, "ALTER DATABASE `$dbname` COLLATE $collation;\n");
		fwrite($fileHandle, "SET FOREIGN_KEY_CHECKS=1;\n");
	}

    $size = round(filesize($fullPath) / 1024 / 1024, 2);
    echo "---------------------------------\nFile size: $size MB\nBackup completed: $fullPath\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
} finally {
    if ($fileHandle) fclose($fileHandle);
}


echo "\n\n";
