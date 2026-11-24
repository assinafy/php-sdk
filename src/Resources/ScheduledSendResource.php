<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

/**
 * Manage scheduled assignment and envelope requests. All methods require an API key
 * or OAuth documents:write, including list(). See docs/SCHEDULED_SENDS.md for the lifecycle.
 * Create requests through assignments()->schedule() or envelopes()->schedule().
 */
class ScheduledSendResource extends AbstractResource
{
    /**
     * GET /accounts/{accountId}/scheduled-sends. No body; newest first, all statuses.
     * Request: ['page' => 1, 'per-page' => 20, 'document_id' => 'document-id'].
     * Response: ['status' => 200, 'message' => '', 'data' => [['resource' => 'scheduled_send',
     * 'id' => 'send-id', 'type' => 'assignment', 'document_ids' => ['document-id'],
     * 'status' => 'pending', 'send_at' => '2027-01-02T12:00:00Z', 'assignment_id' => null,
     * 'envelope_id' => null, 'failure_reason' => null, 'created_at' => '2027-01-01T12:00:00Z',
     * 'processed_at' => null]], 'pagination' => ['current_page' => 1, 'page_count' => 1,
     * 'per_page' => 20, 'total_count' => 1]].
     * @param int $page page starting at 1
     * @param int $perPage requested page size, 1–100; the server may clamp it
     * @param string|null $documentId optional document filter
     * @return array<string, mixed> envelope with pagination metadata
     */
    public function list(int $page = 1, int $perPage = 20, ?string $documentId = null): array
    {
        return $this->withPagination($this->httpClient->get(
            $this->accountPath('scheduled-sends'),
            $this->paginationQuery($page, $perPage, $documentId === null ? [] : ['document_id' => $documentId])
        ));
    }

    /**
     * PUT /accounts/{accountId}/scheduled-sends/{scheduledSendId}/cancel. No body/query.
     * Request: cancel('send-id'). Only pending sends can be cancelled; otherwise 409.
     * Nothing is sent or charged. Response: the ScheduledSend from list().data[0], with
     * ['status' => 'cancelled', 'processed_at' => '2027-01-01T13:00:00Z'].
     * @param string $scheduledSendId pending scheduled send ID
     * @return array<string, mixed> cancelled ScheduledSend
     */
    public function cancel(string $scheduledSendId): array
    {
        return $this->extractData($this->httpClient->put(
            $this->accountPath('scheduled-sends/' . $this->pathSegment($scheduledSendId, 'scheduled send ID') . '/cancel')
        )->getData() ?? []);
    }

    /**
     * PUT /accounts/{accountId}/scheduled-sends/{scheduledSendId}/send. No body/query.
     * Request: sendNow('send-id'). Only pending sends; creates and charges as the scheduling member.
     * Response: the ScheduledSend from list().data[0], with ['status' => 'sent',
     * 'assignment_id' => 'assignment-id', 'processed_at' => '2027-01-01T13:00:00Z'];
     * envelope sends set envelope_id instead. Refusal leaves it pending; cancel explicitly
     * to stop the later attempt. No automatic retry, including after an ambiguous timeout.
     * @param string $scheduledSendId pending scheduled send ID
     * @return array<string, mixed> sent ScheduledSend
     */
    public function sendNow(string $scheduledSendId): array
    {
        return $this->extractData($this->httpClient->put(
            $this->accountPath('scheduled-sends/' . $this->pathSegment($scheduledSendId, 'scheduled send ID') . '/send')
        )->getData() ?? []);
    }
}
