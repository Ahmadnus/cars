<?php

namespace Tests\Unit;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Services\IdCards\ClaudeIdCardReader;
use App\Services\IdCards\IdCardReading;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/**
 * The request the reader actually puts on the wire.
 *
 * Worth a test of its own because it is the one part of this feature that cannot
 * be checked by reading it: the SDK maps `mediaType` to `media_type` and nests
 * the schema under `output_config`, and if a future version of it stops doing
 * either, nothing in the application breaks — the API simply refuses every photo,
 * which looks exactly like a feature that works and never reads a card.
 *
 * No network. The transport is replaced with one that records the request and
 * answers with a response in the Messages API's shape.
 */
class ClaudeIdCardReaderTest extends TestCase
{
    /** The reader, wired to a transport that records and answers. */
    protected function reader(string $answer, int $status = 200): array
    {
        $recorder = new class($answer, $status) implements ClientInterface
        {
            public ?RequestInterface $request = null;

            public function __construct(protected string $answer, protected int $status)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response(
                    $this->status,
                    ['content-type' => 'application/json'],
                    $this->answer,
                );
            }
        };

        $client = new Client(
            apiKey: 'sk-ant-test',
            requestOptions: RequestOptions::with(transporter: $recorder, maxRetries: 0),
        );

        return [
            new ClaudeIdCardReader(
                enabled: true,
                apiKey: 'sk-ant-test',
                model: 'claude-opus-5-5',
                effort: 'low',
                client: $client,
            ),
            $recorder,
        ];
    }

    /** A Messages API response carrying the JSON the schema asked for. */
    protected function answer(array $fields = []): string
    {
        $payload = json_encode(array_merge([
            'document_type' => 'jordanian_id',
            'full_name' => 'رامي سامر محمود الحديد',
            'national_id' => '9981234567',
            'birth_date' => '1998-04-11',
            'gender' => 'male',
            'city' => 'عمّان',
            'address' => '',
            'confidence' => 'high',
        ], $fields), JSON_UNESCAPED_UNICODE);

        return json_encode([
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $payload]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 90],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function test_the_photo_goes_up_as_a_base64_image_block(): void
    {
        [$reader, $recorder] = $this->reader($this->answer());

        $reader->read('the-image-bytes', 'image/png');

        $body = json_decode((string) $recorder->request->getBody(), true);
        $blocks = $body['messages'][0]['content'];

        $this->assertSame('image', $blocks[0]['type']);
        $this->assertSame('base64', $blocks[0]['source']['type']);

        // The names the API expects, not the names the SDK takes as arguments.
        $this->assertSame('image/png', $blocks[0]['source']['media_type']);
        $this->assertSame(base64_encode('the-image-bytes'), $blocks[0]['source']['data']);

        // The instruction follows the photo: the model reads the card it was
        // just handed rather than being told what to do about nothing yet.
        $this->assertSame('text', $blocks[1]['type']);
    }

    public function test_it_asks_for_the_answer_as_a_schema(): void
    {
        [$reader, $recorder] = $this->reader($this->answer());

        $reader->read('bytes', 'image/jpeg');

        $body = json_decode((string) $recorder->request->getBody(), true);

        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame('low', $body['output_config']['effort']);
        $this->assertSame('claude-opus-5-5', $body['model']);

        $schema = $body['output_config']['format']['schema'];

        $this->assertFalse($schema['additionalProperties']);
        $this->assertContains('national_id', $schema['required']);
        $this->assertIsString($body['system']);
    }

    /** The round trip: wire response in, a reading the forms can use out. */
    public function test_it_turns_the_answer_into_a_reading(): void
    {
        [$reader] = $this->reader($this->answer());

        $reading = $reader->read('bytes', 'image/jpeg');

        $this->assertTrue($reading->succeeded());
        $this->assertSame('9981234567', $reading->nationalId);
        $this->assertSame('1998-04-11', $reading->birthDate);
        $this->assertSame('عمّان', $reading->city);
        $this->assertNull($reading->address);
    }

    /**
     * A refusal arrives as a 200 with a stop reason, so it has to be checked
     * before the content is read — otherwise it reads as an unreadable card and
     * staff retry a photo that will be refused again.
     */
    public function test_a_refusal_is_not_mistaken_for_an_unreadable_card(): void
    {
        $refusal = json_encode([
            'id' => 'msg_02',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'general_harms', 'explanation' => null],
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 0],
        ]);

        [$reader] = $this->reader($refusal);

        $reading = $reader->read('bytes', 'image/jpeg');

        $this->assertSame(IdCardReading::OUTCOME_FAILED, $reading->outcome);
    }

    /** A provider that is down is a sentence on screen, never an exception. */
    public function test_a_provider_error_becomes_a_message(): void
    {
        [$reader] = $this->reader(json_encode([
            'type' => 'error',
            'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
        ]), status: 529);

        $reading = $reader->read('bytes', 'image/jpeg');

        $this->assertSame(IdCardReading::OUTCOME_FAILED, $reading->outcome);
        $this->assertNotNull($reading->message);
    }

    /** A PDF never reaches the provider: the image block cannot carry one. */
    public function test_an_unsupported_file_is_refused_without_a_request(): void
    {
        [$reader, $recorder] = $this->reader($this->answer());

        $reading = $reader->read('%PDF-1.4', 'application/pdf');

        $this->assertSame(IdCardReading::OUTCOME_FAILED, $reading->outcome);
        $this->assertNull($recorder->request, 'nothing should have been sent');
    }
}
