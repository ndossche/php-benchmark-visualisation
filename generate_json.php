<?php
require_once __DIR__ . "/config.inc.php";

function generateOutputFiles(array $result, array $realtime_result): void {
    @mkdir("output");
    writeResult("output/data.json", $result);
    writeResult("output/realtime.json", $realtime_result);
    copy("template/index.html", "output/index.html");
}

function writeResult(string $path, array $result): void {
    $file = fopen($path, "w");
    fwrite($file, json_encode($result));
    fclose($file);
}

function readTsv(string $path): array {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }
    // Some old files join two columns in the header: "Std dev\Rel std dev %". Split them again.
    $header = explode("\t", str_replace("\\", "\t", array_shift($lines)));
    $rows = [];
    foreach ($lines as $line) {
        $fields = explode("\t", $line);
        if (count($fields) !== count($header)) {
            continue;
        }
        $rows[] = array_combine($header, $fields);
    }
    return $rows;
}

// Runs git with the given lines as input, and returns the output.
function runGit(array $args, array $input_lines): string {
    // Use a file for the input, and do not capture errors.
    // With pipes, git and PHP could wait for each other forever.
    $input = tmpfile();
    fwrite($input, implode("\n", $input_lines) . "\n");
    rewind($input);

    $process = proc_open(
        ["git", ...$args],
        [0 => $input, 1 => ["pipe", "w"], 2 => STDERR],
        $pipes,
        PHP_SRC_PATH,
    );
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($input);
    if (proc_close($process) !== 0) {
        throw new RuntimeException("git " . implode(" ", $args) . " failed");
    }
    return $output;
}

// Gets the subject and date of many commits, with only two git calls.
// The keys of the result are the given commit IDs (full or short). Unknown commits are left out.
function getCommitInfos(array $commit_ids): array {
    $commit_ids = array_values(array_unique($commit_ids));
    if (!$commit_ids) {
        return [];
    }

    // First get the full IDs, because git log stops at the first unknown commit.
    // cat-file prints one line for each input line, in the same order.
    $resolved = explode("\n", runGit(["cat-file", "--batch-check=%(objectname) %(objecttype)"], $commit_ids));
    $full_ids = [];
    foreach ($commit_ids as $i => $commit_id) {
        [$full_id, $type] = explode(" ", $resolved[$i]) + [1 => null];
        if ($type === "commit") {
            $full_ids[$full_id][] = $commit_id;
        }
    }
    if (!$full_ids) {
        return [];
    }

    $log = runGit(["log", "--no-walk=unsorted", "--stdin", "--format=%H%x00%s%x00%cI"], array_keys($full_ids));
    $infos = [];
    foreach (explode("\n", rtrim($log, "\n")) as $line) {
        [$full_id, $subject, $date] = explode("\0", $line);
        foreach ($full_ids[$full_id] as $commit_id) {
            $infos[$commit_id] = [
                "subject" => $subject,
                "date" => DateTimeImmutable::createFromFormat(DATE_ATOM, $date),
            ];
        }
    }
    return $infos;
}

// Removes the version from a benchmark name, e.g. "Laravel 12.11.0 demo app" -> "Laravel demo app".
// This way, a benchmark stays one line in the chart when the app version changes.
function getBenchmarkFamily(string $test_name): string {
    return preg_replace('/ \d+(\.\d+)+ /', ' ', $test_name);
}

function collectRealtimeData(): array {
    $runs = [];
    $benchmarks = [];

    foreach (glob(REALTIME_BENCHMARK_DATA_PATH . "/*/*/result.tsv") as $result_file) {
        $dir = dirname($result_file);
        $environment = readTsv("$dir/environment.tsv")[0] ?? null;
        if ($environment === null) {
            echo "Could not read environment for $dir\n";
            continue;
        }

        $commit_id = null;
        $previous_commit_id = null;
        $baseline_commit_id = null;
        $results = [];
        foreach (readTsv($result_file) as $row) {
            if ($row["PHP"] === "PHP - previous master") {
                $previous_commit_id = $row["PHP Commit hash"];
            } else if (str_starts_with($row["PHP"], "PHP - baseline")) {
                $baseline_commit_id = $row["PHP Commit hash"];
            }
            $variant = match ($row["PHP"]) {
                "PHP - master" => "diff",
                "PHP - master (JIT)" => "jit_diff",
                default => null,
            };
            // Older files call this column "Average diff %".
            $diff = $row["Mean diff %"] ?? $row["Average diff %"] ?? null;
            if ($variant === null || !is_numeric($diff)) {
                continue;
            }
            $commit_id = $row["PHP Commit hash"];
            // Too noisy, and not measured since August 2025.
            if ($row["Test name"] === "micro_bench.php") {
                continue;
            }
            $family = getBenchmarkFamily($row["Test name"]);
            $benchmarks[] = $family;
            $results[$family]["name"] = $row["Test name"];
            $results[$family]["settings"] = [
                "warmup" => (int) $row["Test warmup"],
                "iterations" => (int) $row["Test iterations"],
                "requests" => (int) $row["Test requests"],
            ];
            $results[$family][$variant] = (float) $diff;
        }
        if ($commit_id === null) {
            echo "No master results found in $result_file\n";
            continue;
        }

        $runs[] = [
            "date" => $environment["Time"],
            "machine" => $environment["Name"],
            "cpu" => $environment["CPU"] ?? "",
            "gcc" => $environment["GCC"] ?? "",
            "commit_info" => [
                "id" => $commit_id,
                "previous_id" => $previous_commit_id,
                "baseline_id" => $baseline_commit_id,
            ],
            "results" => $results,
        ];
    }

    $commit_infos = getCommitInfos(array_map(fn ($run) => $run["commit_info"]["id"], $runs));
    foreach ($runs as &$run) {
        $run["commit_info"]["subject"] = $commit_infos[$run["commit_info"]["id"]]["subject"] ?? null;
    }
    unset($run);

    usort($runs, fn ($a, $b) => strcmp($a["date"], $b["date"]));

    return [
        "runs" => $runs,
        "benchmarks" => array_values(array_unique($benchmarks)),
    ];
}

// Orders branches from newest to oldest version, with master last.
function compareBranches(string $a, string $b): int {
    if ($a === "master" || $b === "master") {
        return ($a === "master") <=> ($b === "master");
    }
    return version_compare(substr($b, 4), substr($a, 4));
}

function main(): void {
    $result = [];
    $benchmarks = [];

    $summaries = [];
    foreach (glob(BENCHMARK_DATA_PATH . "/*/*/summary.json") as $dir) {
        preg_match('|/[a-z0-9]+/([a-z0-9]+)/summary.json$|', $dir, $matches);
        list($file, $commit_id) = $matches;
        $summaries[$commit_id] = $file;
    }

    $commit_infos = getCommitInfos(array_keys($summaries));
    echo "Retrieved info about ", count($commit_infos), " of ", count($summaries), " commits\n";

    foreach ($summaries as $commit_id => $file) {
        $commit_info = $commit_infos[$commit_id] ?? null;
        if ($commit_info === null) {
            echo "Could not retrieve info about commit $commit_id\n";
            continue;
        }

        $summary_data = json_decode(file_get_contents(BENCHMARK_DATA_PATH . $file));
        if (!is_object($summary_data)) {
            echo "Could not parse summary for commit $commit_id: ", json_last_error_msg(), "\n";
            continue;
        }
        // We only started recording the branch after the 8.3 split-off into 8.4.
        $branch = $summary_data->branch ?? "master";
        unset($summary_data->branch);
        foreach (array_keys((array) $summary_data) as $benchmark_name) {
            $benchmarks[] = $benchmark_name;
        }

        $result_data = [
            "commit_info" => [
                "subject" => $commit_info["subject"],
                "date" => $commit_info["date"],
                "id" => $commit_id,
            ],
            "summary" => $summary_data,
        ];
        $result[$branch][] = $result_data;
    }

    // Leave out branches with almost no data, e.g. PHP-8.2.
    $result = array_filter($result, fn ($entries) => count($entries) >= 10);
    uksort($result, "compareBranches");

    $benchmarks = array_unique($benchmarks);
    $benchmarks = array_filter($benchmarks, fn ($name) => !str_contains($name, "Laravel"));
    $benchmarks = array_values($benchmarks);
    generateOutputFiles([
        'branches' => $result,
        'benchmarks' => $benchmarks
    ], collectRealtimeData());
}

main();
