<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('db_query')]
#[Description('Runs one read-only query on the live website\'s database (SELECT, WITH, SHOW, DESCRIBE or EXPLAIN; the database refuses changes) and returns up to 200 rows as tab-separated text. The broker replaces people\'s details in the result with placeholders first. Passwords, tokens, secrets, sessions and the broker\'s stored values aren\'t shown.')]
class DatabaseQueryTool extends Tool
{
    use ForAdmins;

    public const ROWS = 200;

    /** Columns and tables never shown. */
    private const SECRET = '/password|token|secret|payload|api_key|private_key/i';

    private const SECRET_TABLES = '/\b(sessions|broker_values|cache|cache_locks)\b/i';

    public function handle(Request $request): Response
    {
        if (! $this->admin()) {
            return Response::error('Only admins\' chats can query the database.');
        }
        $sql = trim(rtrim(trim((string) $request->get('sql', '')), ';'));
        if (! preg_match('/^(select|with|show|describe|desc|explain)\b/i', $sql) || str_contains($sql, ';')) {
            return Response::error('sql is one read-only query: SELECT, WITH, SHOW, DESCRIBE or EXPLAIN.');
        }
        if (preg_match(self::SECRET, $sql) || preg_match(self::SECRET_TABLES, $sql) || preg_match('/\binto\s+(outfile|dumpfile)\b|load_file\s*\(/i', $sql)) {
            return Response::error('That query reads passwords, tokens, secrets, sessions or the broker\'s stored values, which aren\'t shown.');
        }

        $db = DB::connection();
        $sqlite = $db->getDriverName() === 'sqlite';
        try {
            if ($sqlite) {
                $db->statement('PRAGMA query_only = ON');
            } else {
                $db->statement('SET SESSION max_statement_time = 30');
                $db->statement('START TRANSACTION READ ONLY');
            }
            $rows = $db->select($sql);
        } catch (Throwable $e) {
            return Response::error($this->safe(mb_substr($e->getMessage(), 0, 1500)));
        } finally {
            try {
                $sqlite ? $db->statement('PRAGMA query_only = OFF') : $db->statement('ROLLBACK');
            } catch (Throwable) {
            }
        }

        if ($rows === []) {
            return Response::text('No rows.');
        }
        $columns = array_keys((array) $rows[0]);
        $lines = [implode("\t", $columns)];
        foreach (array_slice($rows, 0, self::ROWS) as $row) {
            $row = (array) $row;
            // A setting whose name says it's secret keeps its value hidden too (in case it isn't encrypted).
            $secretSetting = isset($row['key'], $row['value']) && preg_match(self::SECRET, (string) $row['key']);
            $lines[] = implode("\t", array_map(function ($column) use ($row, $secretSetting) {
                $value = $row[$column];
                if (preg_match(self::SECRET, (string) $column) || ($secretSetting && $column === 'value')) {
                    return $value === null ? 'NULL' : '[hidden]';
                }

                return $value === null ? 'NULL' : mb_substr(str_replace(["\t", "\n", "\r"], ' ', (string) $value), 0, 500);
            }, $columns));
        }
        $more = count($rows) > self::ROWS ? "\n(".count($rows).' rows; the first '.self::ROWS.' shown.)' : '';

        return Response::text($this->safe(implode("\n", $lines)).$more);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['sql' => $schema->string()->description('One read-only query, for example SELECT id, name FROM users LIMIT 10.')->required()];
    }
}
