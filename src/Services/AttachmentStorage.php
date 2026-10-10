<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use YousefAman\FeedbackBoard\Models\FeedbackAttachment;
use YousefAman\FeedbackBoard\Models\FeedbackComment;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Support\Images;

class AttachmentStorage
{
    public function allowedRules(): array
    {
        $mimes = implode(',', config('feedback-board.attachments.allowed_mimes', []));
        $max = (int) config('feedback-board.attachments.max_kb', 10240);
        return ['file', 'mimes:'.$mimes, 'max:'.$max];
    }

    /**
     * The configured extensions that are allow-listed images: what comment composers accept.
     *
     * @return list<string>
     */
    public function imageMimes(): array
    {
        $configured = array_map(
            fn ($mime) => strtolower(trim((string) $mime)),
            (array) config('feedback-board.attachments.allowed_mimes', []),
        );

        return array_values(array_intersect(Images::extensions(), $configured));
    }

    public function imageRules(): array
    {
        $max = (int) config('feedback-board.attachments.max_kb', 10240);

        return ['file', 'mimes:'.implode(',', $this->imageMimes()), 'max:'.$max];
    }

    public function maxFiles(): int
    {
        return max(1, (int) config('feedback-board.attachments.max_files', 10));
    }

    /**
     * Human-readable upload limits for the form hint. The size never exceeds PHP's own
     * upload_max_filesize (which rejects larger files before validation runs) nor the
     * `max:` rule of Livewire's temporary-upload validation.
     *
     * @return array{types: string, size: string}
     */
    public function limits(): array
    {
        $mimes = (array) config('feedback-board.attachments.allowed_mimes', []);
        $kb = (int) config('feedback-board.attachments.max_kb', 10240);
        $php = self::iniSizeToKb((string) ini_get('upload_max_filesize'));
        $livewire = self::livewireUploadLimitKb();

        return [
            'types' => implode(', ', array_map(fn ($mime) => strtoupper((string) $mime), $mimes)),
            'size' => self::megabytes(min(array_filter([$kb, $php, $livewire]))),
        ];
    }

    /** The `max:` kilobytes of Livewire's temporary-upload rules, if any. */
    private static function livewireUploadLimitKb(): ?int
    {
        foreach (\Livewire\Features\SupportFileUploads\FileUploadConfiguration::rules() as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/', $rule, $m) && (int) $m[1] > 0) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /** "2M" → 2048, "512K" → 512, "1G" → 1048576, bytes → KB; null when unparsable or not positive. */
    public static function iniSizeToKb(string $value): ?int
    {
        if (! preg_match('/^\s*(\d+)\s*([kmg]?)\s*$/i', $value, $m)) {
            return null;
        }

        $kb = match (strtolower($m[2])) {
            'g' => (int) $m[1] * 1024 * 1024,
            'm' => (int) $m[1] * 1024,
            'k' => (int) $m[1],
            default => intdiv((int) $m[1], 1024),
        };

        return $kb > 0 ? $kb : null;
    }

    /** 10240 → "10", 512 → "0.5", 1536 → "1.5". */
    public static function megabytes(int $kb): string
    {
        $mb = number_format($kb / 1024, 1, '.', '');

        return str_ends_with($mb, '.0') ? substr($mb, 0, -2) : $mb;
    }

    /** 245760 → "240 KB", 1572864 → "1.5 MB" (at least 1 KB). */
    public static function humanSize(int $bytes): string
    {
        $kb = max(1, (int) round($bytes / 1024));

        return $kb < 1024
            ? __('feedback-board::feedback-board.files.kb', ['size' => $kb])
            : __('feedback-board::feedback-board.files.mb', ['size' => self::megabytes($kb)]);
    }

    public function store(UploadedFile $file, FeedbackItem $item, ?FeedbackComment $comment = null): FeedbackAttachment
    {
        Validator::make(['file' => $file], ['file' => $this->allowedRules()])->validate();

        $disk = config('feedback-board.attachments.disk', 'local');
        $dir = config('feedback-board.attachments.directory', 'feedback-board').'/'.$item->getKey();
        $path = $file->store($dir, $disk);
        throw_unless($path !== false, \RuntimeException::class, 'Attachment storage failed.');

        return $item->attachments()->create([
            'feedback_comment_id' => $comment?->getKey(),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }
}
