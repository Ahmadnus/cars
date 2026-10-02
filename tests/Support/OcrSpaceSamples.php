<?php

namespace Tests\Support;

/**
 * Real answers from OCR.space, kept as fixtures.
 *
 * Not invented: the card below was rendered as a Jordanian ID front and read by
 * the live service with engine 3, and the coordinates are the ones it returned.
 * That matters, because the thing being tested is a quirk of the real output —
 * the service put two labels and one value on one row, so the row below holds the
 * first label's value. A fixture written by hand would have been tidier and would
 * have tested nothing.
 */
class OcrSpaceSamples
{
    /**
     * A card the service read cleanly.
     *
     * @param  list<array{0: string, 1: int, 2: int, 3: int}>|null  $lines  text, left, top, width
     * @return array<string, mixed>
     */
    public static function response(?array $lines = null, int $height = 46): array
    {
        $lines ??= self::jordanianCard();

        return [
            'ParsedResults' => [[
                'TextOverlay' => [
                    'Lines' => array_map(static fn (array $line) => [
                        'Words' => [[
                            'WordText' => $line[0],
                            'Left' => $line[1],
                            'Top' => $line[2],
                            'Height' => $height,
                            'Width' => $line[3],
                        ]],
                        'MaxHeight' => $height,
                        'MinTop' => $line[2],
                    ], $lines),
                    'HasOverlay' => true,
                    'Message' => 'Total lines: '.count($lines),
                ],
                'FileParseExitCode' => 1,
                'ParsedText' => implode("\n", array_column($lines, 0))."\n",
                'ErrorMessage' => null,
                'ErrorDetails' => null,
            ]],
            'OCRExitCode' => 1,
            'IsErroredOnProcessing' => false,
            'ErrorMessage' => null,
            'ErrorDetails' => null,
            'SearchablePDFURL' => null,
            'ProcessingTimeInMilliseconds' => 1687,
        ];
    }

    /**
     * The front of a Jordanian ID as engine 3 returned it.
     *
     * Note row 517: the mother's national number sits on the same row as its own
     * label *and* as the birth-date label, whose value is on the row below. This
     * is the shape that makes "the value is the next line" pick the wrong number.
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int}>
     */
    public static function jordanianCard(): array
    {
        return [
            ['المملكة الأردنية الهاشمية', 148, 40, 550],
            ['دائرة الأحوال المدنية والجوازات', 148, 118, 550],
            ['الرقم الوطني', 474, 282, 224],
            ['9981234567', 108, 282, 237],
            ['الاسم', 593, 358, 104],
            ['رامي سامر محمود الحديد', 32, 358, 446],
            ['اسم الأم', 579, 435, 119],
            ['فاطمة علي أحمد', 123, 435, 304],
            ['الرقم الوطني للأم', 456, 517, 242],
            ['9752223334', 25, 517, 235],
            ['تاريخ الميلاد', 571, 517, 126],
            ['1998/04/11', 148, 593, 217],
            ['مكان الولادة', 488, 593, 209],
            ['عمان', 275, 670, 83],
            ['الجنس', 582, 746, 115],
            ['ذكر', 409, 759, 61],
            ['تاريخ الإصدار', 466, 834, 231],
            ['2024/02/20', 141, 834, 224],
            ['تاريخ الانتهاء', 488, 910, 209],
            ['2034/02/19', 126, 910, 224],
        ];
    }

    /**
     * What the service answers when it will not do the job: HTTP 200, with the
     * failure inside the body. This is engine 1 refusing Arabic, verbatim.
     *
     * @return array<string, mixed>
     */
    public static function refused(): array
    {
        return [
            'ParsedResults' => null,
            'OCRExitCode' => 99,
            'IsErroredOnProcessing' => true,
            'ErrorMessage' => ["E201: Value for parameter 'language' is invalid"],
            'ErrorDetails' => '',
            'ProcessingTimeInMilliseconds' => 0,
        ];
    }
}
