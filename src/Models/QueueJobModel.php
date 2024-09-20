<?php

declare(strict_types=1);

/**
 * This file is part of CodeIgniter Queue.
 *
 * (c) CodeIgniter Foundation <admin@codeigniter.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace CodeIgniter\Queue\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\I18n\Time;
use CodeIgniter\Model;
use CodeIgniter\Queue\Entities\QueueJob;
use CodeIgniter\Queue\Enums\Status;
use ReflectionException;

class QueueJobModel extends Model
{
    protected $table            = 'queue_jobs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = QueueJob::class;
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['queue', 'payload', 'priority', 'status', 'attempts', 'available_at'];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'int';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';

    // Validation
    protected $skipValidation = true;

    // Callbacks
    protected $allowCallbacks = false;

    /**
     * Get the oldest item from the queue.
     *
     * @throws ReflectionException
     */
    public function getFromQueue(string $name, array $priority): ?QueueJob
    {
        // For SQLite3 memory database this will cause problems
        // so check if we're not in the testing environment first.
        if ($this->db->database !== ':memory:' && $this->db->connID !== false) {
            // Make sure we still have the connection
            $this->db->reconnect();
        }
        // Start transaction
        $this->db->transStart();

        // Prepare SQL
        $builder = $this->builder()
            ->where('queue', $name)
            ->where('status', Status::PENDING->value)
            ->where('available_at <=', Time::now()->timestamp)
            ->limit(1);

        $builder = $this->setPriority($builder, $priority);
        $sql     = $builder->getCompiledSelect();
echo $sql;
        $query = $this->db->query($this->skipLocked($sql));
        if ($query === false) {
            return null;
        }
        /** @var QueueJob|null $row */
        $row = $query->getCustomRowObject(0, QueueJob::class);

        if ($row !== null) {
            // Change status
            $this->update($row->id, ['status' => Status::RESERVED->value]);
        }
        // Complete transaction
        $this->db->transComplete();

        return $row;
    }

    /**
     * Skip locked if DB driver support it.
     */
    private function skipLocked(string $sql): string
    {
        if ($this->db->DBDriver === 'SQLite3' || config('Queue')->database['skipLocked'] === false) {
            return $sql;
        }

        if ($this->db->DBDriver === 'SQLSRV') {
            $replace = 'WITH (ROWLOCK,UPDLOCK,READPAST) WHERE';

            return str_replace('WHERE', $replace, $sql);
        }

        if ($this->db->DBDriver === 'OCI8') {
            // extract and remove ORDER BY part from the query
            $orderBy = '';
            preg_match('/ORDER BY\s+.*?(?=\s+OFFSET|\s*$)/is', $sql, $matches);
            if (isset($matches[0])) {
                $orderBy = $matches[0];
            }
            $sql = preg_replace('/ORDER BY\s+.*?(?=\s+OFFSET|\s*$)/is', '', $sql);
            // remove LIMIT part from the query
            $sql = preg_replace('/ OFFSET .*/', '', $sql);
            // modify SELECT
            $replace = sprintf('SELECT *, ROW_NUMBER() OVER (%s) AS rn', $orderBy);
            $sql = str_replace('SELECT *', $replace, $sql);
            // prepare final query
            $sql = "SELECT * FROM ({$sql}) WHERE rn = 1";
        }
        echo $sql . ' FOR UPDATE SKIP LOCKED';
        return $sql . ' FOR UPDATE SKIP LOCKED';
    }

    /**
     * Handle priority of the queue.
     */
    private function setPriority(BaseBuilder $builder, array $priority): BaseBuilder
    {
        $builder->whereIn('priority', $priority);

        if ($priority !== ['default']) {
            if ($this->db->DBDriver !== 'MySQLi') {
                $builder->orderBy(
                    'CASE priority '
                    . implode(
                        ' ',
                        array_map(static fn ($value, $key) => "WHEN '{$value}' THEN {$key}", $priority, array_keys($priority))
                    )
                    . ' END',
                    '',
                    false
                );
            } else {
                $builder->orderBy(
                    'FIELD(priority, '
                    . implode(
                        ',',
                        array_map(static fn ($value) => "'{$value}'", $priority)
                    )
                    . ')',
                    '',
                    false
                );
            }
        }

        $builder
            ->orderBy('available_at', 'asc')
            ->orderBy('id', 'asc');

        return $builder;
    }
}
