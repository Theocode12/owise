<?php

namespace Owise\Api;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Owise\Data\Statement;
use Owise\Exceptions\WiseApiError;

/**
 * Fetch Wise balance statements for the current profile.
 */
final class StatementsApi extends AccountApi
{
    /** The maximum statement interval in days enforced by Wise. @var int */
    private const MAX_INTERVAL_DAYS = 469;

    /** The supported statement types. @var list<string> */
    private const TYPES = ['COMPACT', 'FLAT'];

    /**
     * The supported download formats with their paths and accept headers.
     *
     * Kept in place for future non-JSON statement downloads: Wise documents these but does not
     * yet serve them on the `2026Q4` API (they currently return 404).
     *
     * @var array<string, array{path: string, accept: string, ext: string}>
     */
    private const FORMATS = [
        'json' => ['path' => 'statement.json', 'accept' => 'application/json', 'ext' => 'json'],
        'csv' => ['path' => 'statement.csv', 'accept' => 'text/csv', 'ext' => 'csv'],
        'pdf' => ['path' => 'statement.pdf', 'accept' => 'application/pdf', 'ext' => 'pdf'],
        'xlsx' => ['path' => 'statement.xlsx', 'accept' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ext' => 'xlsx'],
        'camt053' => ['path' => 'statement.xml', 'accept' => 'application/xml', 'ext' => 'xml'],
        'mt940' => ['path' => 'statement.mt940', 'accept' => 'text/plain', 'ext' => 'mt940'],
        'qif' => ['path' => 'statement.qif', 'accept' => 'application/octet-stream', 'ext' => 'qif'],
    ];

    /**
     * Get a JSON statement for a balance.
     *
     * SCA-protected outside the US, CA, AU, NZ, SG and MY.
     *
     * @param  int  $balanceId  The balance ID.
     * @param  string  $currency  The currency code.
     * @param  DateTimeInterface  $from  The interval start.
     * @param  DateTimeInterface  $to  The interval end.
     * @param  string  $type  The statement type.
     * @param  string|null  $locale  The statement locale, if any.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return Statement The statement.
     *
     * @throws InvalidArgumentException
     * @throws WiseApiError
     */
    public function get(
        int $balanceId,
        string $currency,
        DateTimeInterface $from,
        DateTimeInterface $to,
        string $type = 'COMPACT',
        ?string $locale = null,
        ?int $profileId = null,
    ): Statement {
        [$query] = $this->query($currency, $from, $to, $type, $locale);

        return Statement::fromArray($this->client->requestOperation(
            'balances.statement',
            $this->statementParameters($profileId, $balanceId, self::FORMATS['json']['path']),
            query: $query,
        ));
    }

    /**
     * Build the validated statement query and normalized interval.
     *
     * @param  string  $currency  The currency code.
     * @param  DateTimeInterface  $from  The interval start.
     * @param  DateTimeInterface  $to  The interval end.
     * @param  string  $type  The statement type.
     * @param  string|null  $locale  The statement locale, if any.
     * @return array{0: array<string, mixed>, 1: DateTimeImmutable, 2: DateTimeImmutable} The query and interval bounds.
     *
     * @throws InvalidArgumentException
     */
    private function query(
        string $currency,
        DateTimeInterface $from,
        DateTimeInterface $to,
        string $type,
        ?string $locale,
    ): array {
        $type = strtoupper($type);

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Invalid statement type '{$type}'; expected COMPACT or FLAT.");
        }

        $start = DateTimeImmutable::createFromInterface($from)->setTimezone(new DateTimeZone('UTC'));
        $end = DateTimeImmutable::createFromInterface($to)->setTimezone(new DateTimeZone('UTC'));

        if ($end <= $start) {
            throw new InvalidArgumentException('The statement interval end must be after the interval start.');
        }

        if (($end->getTimestamp() - $start->getTimestamp()) > self::MAX_INTERVAL_DAYS * 86_400) {
            throw new InvalidArgumentException('The statement interval cannot exceed 469 days (Wise limit).');
        }

        $query = array_filter([
            'currency' => strtoupper($currency),
            'intervalStart' => $start->format('Y-m-d\TH:i:s.v\Z'),
            'intervalEnd' => $end->format('Y-m-d\TH:i:s.v\Z'),
            'type' => $type,
            'statementLocale' => $locale,
        ], fn (mixed $value): bool => $value !== null);

        return [$query, $start, $end];
    }

    /**
     * Build parameters for the JSON or binary statement operation.
     *
     * @return array{profileId: int, balanceId: int, statementPath: string}
     */
    private function statementParameters(?int $profileId, int $balanceId, string $statementPath): array
    {
        return [
            'profileId' => $this->profileId($profileId),
            'balanceId' => $balanceId,
            'statementPath' => $statementPath,
        ];
    }
}
