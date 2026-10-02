<?php

namespace App\Services\IdCards;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\OutputConfig;
use Anthropic\Messages\TextBlockParam;
use Anthropic\RequestOptions;
use Illuminate\Support\Facades\Log;

/**
 * Reads an ID card with Claude's vision model.
 *
 * The request is shaped so the answer can only be data: a JSON schema the model
 * must fill, every field a string, and an empty string as the way to say "not
 * readable". That removes the parsing most of these integrations spend their
 * bugs on — there is no prose to strip, and a creased card comes back as empty
 * fields rather than as an apology this code would have to interpret.
 *
 * Nothing the card says is ever logged. A failure records what went wrong with
 * the provider and which fields came back filled, never their values: the whole
 * point of keeping these photos on a private disk is lost if the name and the
 * national number end up in a log file that ships to a crash reporter.
 */
class ClaudeIdCardReader implements IdCardReader
{
    /** What the API accepts as an image, and what a phone camera produces. */
    public const SUPPORTED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        protected bool $enabled,
        protected ?string $apiKey,
        protected string $model = 'claude-opus-5-5',
        protected string $effort = 'low',
        protected int $timeout = 60,
        protected ?Client $client = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && filled($this->apiKey);
    }

    public function read(string $bytes, string $mimeType): IdCardReading
    {
        if (! $this->isEnabled()) {
            return IdCardReading::disabled();
        }

        if (! in_array($mimeType, self::SUPPORTED, true)) {
            return IdCardReading::failed('نوع الملف غير مدعوم للقراءة. استخدم صورة JPG أو PNG.');
        }

        if ($bytes === '') {
            return IdCardReading::failed('الصورة فارغة أو تعذّر قراءتها من القرص.');
        }

        try {
            $message = $this->client()->messages->create(
                maxTokens: 4096,
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ImageBlockParam::with(
                            source: Base64ImageSource::with(
                                data: base64_encode($bytes),
                                mediaType: $mimeType,
                            ),
                        ),
                        TextBlockParam::with(text: 'اقرأ هذه الهوية واستخرج الحقول المطلوبة.'),
                    ],
                ]],
                model: $this->model,
                outputConfig: OutputConfig::with(
                    effort: $this->effort,
                    format: JSONOutputFormat::with(schema: self::schema()),
                ),
                system: self::INSTRUCTIONS,
            );
        } catch (APITimeoutException) {
            return IdCardReading::failed('استغرقت قراءة الهوية وقتاً طويلاً. حاول مرة أخرى.');
        } catch (RateLimitException) {
            return IdCardReading::failed('خدمة قراءة الهوية مشغولة حالياً. حاول بعد قليل.');
        } catch (APIConnectionException) {
            return IdCardReading::failed('تعذّر الاتصال بخدمة قراءة الهوية.');
        } catch (APIStatusException $e) {
            Log::warning('[id-reader] رفضت الخدمة قراءة الصورة.', [
                'status' => $e->status,
                'type' => $e->type?->value,
            ]);

            return IdCardReading::failed('تعذّرت قراءة الهوية حالياً. أدخل البيانات يدوياً.');
        } catch (\Throwable $e) {
            report($e);

            return IdCardReading::failed('تعذّرت قراءة الهوية حالياً. أدخل البيانات يدوياً.');
        }

        /*
         | A safety classifier may decline an image rather than error on it, and
         | it arrives as a normal 200 — so the stop reason is checked before the
         | content is read, or this would look like an unreadable card.
         */
        if ($message->stopReason === 'refusal') {
            Log::warning('[id-reader] أوقفت الخدمة الاستجابة.', [
                'category' => $message->stopDetails?->category,
            ]);

            return IdCardReading::failed('تعذّرت قراءة هذه الصورة. أدخل البيانات يدوياً.');
        }

        $fields = $this->decode($message->content);

        if ($fields === null) {
            return IdCardReading::failed('جاء رد غير متوقع من خدمة القراءة.');
        }

        $reading = IdCardReading::fromModel($fields);

        Log::info('[id-reader] تمت قراءة صورة هوية.', [
            'outcome' => $reading->outcome,
            'document_type' => $reading->documentType,
            'confidence' => $reading->confidence,
            // Keys only. The values are the applicant's identity details.
            'filled' => array_keys($reading->fields()),
            'unclear' => $reading->unclear,
        ]);

        return $reading;
    }

    /**
     * The first text block, as an array.
     *
     * Thinking blocks precede the answer on this model, so the blocks are walked
     * rather than indexed — `content[0]->text` would throw the moment the model
     * returns one.
     *
     * @param  array<int, object>  $content
     * @return array<string, mixed>|null
     */
    protected function decode(array $content): ?array
    {
        foreach ($content as $block) {
            if (($block->type ?? null) !== 'text') {
                continue;
            }

            $decoded = json_decode((string) $block->text, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    protected function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: $this->apiKey,
            requestOptions: RequestOptions::with(
                timeout: (float) $this->timeout,
                // One retry, not two: a receptionist is waiting on this, and a
                // provider that failed twice is better reported than waited on.
                maxRetries: 1,
            ),
        );
    }

    /**
     * What the model is asked to fill.
     *
     * Every field is a string with `""` for "not readable" rather than a
     * nullable type: it keeps the schema inside the subset structured outputs
     * accept, and it gives the model one unambiguous way to say it could not
     * read a line — which is the answer this feature most needs to be honest.
     *
     * @return array<string, mixed>
     */
    protected static function schema(): array
    {
        $optional = static fn (string $description): array => [
            'type' => 'string',
            'description' => $description.' اتركه "" إن لم يكن مقروءاً بوضوح.',
        ];

        return [
            'type' => 'object',
            'properties' => [
                'document_type' => [
                    'type' => 'string',
                    'enum' => [
                        'jordanian_id',
                        'other_national_id',
                        'passport',
                        'driving_license',
                        'not_an_identity_document',
                    ],
                    'description' => 'نوع الوثيقة في الصورة.',
                ],
                'full_name' => $optional('الاسم الكامل كما هو مطبوع على الوثيقة.'),
                'national_id' => $optional('الرقم الوطني لصاحب الوثيقة بالأرقام اللاتينية.'),
                'birth_date' => $optional('تاريخ الميلاد الميلادي بصيغة YYYY-MM-DD.'),
                // Not an enum, unlike the two above: the empty string has to be
                // allowed, and an enum that has to carry "" as a member is a
                // description doing the work anyway.
                'gender' => $optional('الجنس: "male" أو "female".'),
                'city' => $optional('مكان الولادة أو المدينة.'),
                'address' => $optional('العنوان إن كان مطبوعاً على الوثيقة.'),
                'confidence' => [
                    'type' => 'string',
                    'enum' => ['high', 'medium', 'low'],
                    'description' => 'مدى وضوح الصورة ودقة القراءة.',
                ],
            ],
            'required' => [
                'document_type', 'full_name', 'national_id', 'birth_date',
                'gender', 'city', 'address', 'confidence',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * The reading instructions.
     *
     * Most of them exist because of a specific way a Jordanian card misleads a
     * reader: it prints two national numbers — the holder's and their mother's —
     * three dates, of which only one is the birth date, and field labels in the
     * same script as the values. Each line below is one of those traps closed.
     */
    protected const INSTRUCTIONS = <<<'AR'
        أنت تقرأ صورة وثيقة هوية لمركز تدريب قيادة في الأردن، وتستخرج منها الحقول المطبوعة فقط.

        القواعد:
        - لا تخمّن أبداً. أي حقل غير مقروء بوضوح، أو غير موجود على الوثيقة، اتركه "".
        - الاسم الكامل: كما هو مطبوع تماماً، بنفس لغة الوثيقة، دون عناوين الحقول ودون ألقاب. إن كان الاسم مطبوعاً بالعربية والإنجليزية، اختر العربية.
        - الهوية الأردنية تطبع رقمين وطنيين: رقم صاحب الهوية، ورقم والدته. خُذ رقم صاحب الهوية فقط، وهو المطبوع تحت عنوان "الرقم الوطني" في مقدّمة البطاقة. ولا تأخذ الرقم التسلسلي للبطاقة.
        - الوثيقة تحمل عدّة تواريخ (الميلاد، الإصدار، الانتهاء). خُذ تاريخ الميلاد فقط، وبصيغة YYYY-MM-DD ميلادية. إن كان التاريخ هجرياً ولا يوجد ما يقابله بالميلادي، اتركه "".
        - حوّل أي أرقام عربية-هندية (٠١٢٣٤٥٦٧٨٩) إلى أرقام لاتينية.
        - الجنس: "male" للذكر و"female" للأنثى فقط.
        - إن لم تكن الصورة وثيقة هوية إطلاقاً، اجعل document_type هي "not_an_identity_document" واترك بقية الحقول "".
        - اقرأ ما هو مكتوب. لا تصحّح إملاء اسم، ولا تكمل اسماً ناقصاً، ولا تستنتج حقلاً من حقل آخر.
        AR;
}
