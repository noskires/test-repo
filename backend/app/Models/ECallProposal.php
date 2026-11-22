<?php

namespace App\Models;

use App\Billing\BoqQuantity;
use App\Enums\ECallStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OverflowException;
use Throwable;

/**
 * One revision of the Site Vendor's FSR (uploaded file + assessment notes) and BOQ for an E-Call.
 * Revisions start at 1 and grow with each resubmission after Returned. Earlier revisions are kept unchanged.
 */
#[Fillable([
    'revision', 'assessment_notes',
    'fsr_disk', 'fsr_path', 'fsr_original_name', 'fsr_mime_type', 'fsr_size_bytes',
    'total_millicentavos', 'submitted_by_user_id', 'submitted_at',
])]
class ECallProposal extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'e_call_id' => 'integer',
            'revision' => 'integer',
            'fsr_size_bytes' => 'integer',
            'total_millicentavos' => 'integer',
            'submitted_by_user_id' => 'integer',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ECall, $this>
     */
    public function eCall(): BelongsTo
    {
        return $this->belongsTo(ECall::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return HasMany<ECallProposalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ECallProposalLine::class)->orderBy('id');
    }

    /**
     * Submit the next FSR + BOQ revision and move the E-Call to For EIC Review. The E-Call row is locked so the
     * status check and the revision number can't race. Each line copies its Price List item's details and price.
     *
     * @param  list<array{item: PriceListItem, quantity_thousandths: int}>  $lines
     *
     * @throws ValidationException when the E-Call no longer accepts a proposal or a total is too large to store.
     */
    public static function submit(ECall $eCall, User $submitter, string $assessmentNotes, UploadedFile $fsr, array $lines): self
    {
        $disk = config('pss.e_call_attachments.disk');
        $fsrPath = null;

        try {
            return DB::transaction(function () use ($eCall, $submitter, $assessmentNotes, $fsr, $lines, $disk, &$fsrPath): self {
                $lockedECall = ECall::query()->lockForUpdate()->findOrFail($eCall->id);

                if (! $lockedECall->status->acceptsProposal()) {
                    throw ValidationException::withMessages([
                        'e_call' => 'An FSR and BOQ can only be submitted while the E-Call is For Vendor Assessment or Returned.',
                    ]);
                }

                $lineAttributes = static::lineAttributes($lines);

                $fsrPath = $fsr->store("e-calls/{$lockedECall->id}/proposals", $disk);

                $proposal = $lockedECall->proposals()->create([
                    'revision' => (int) $lockedECall->proposals()->max('revision') + 1,
                    'assessment_notes' => $assessmentNotes,
                    'fsr_disk' => $disk,
                    'fsr_path' => $fsrPath,
                    'fsr_original_name' => $fsr->getClientOriginalName(),
                    'fsr_mime_type' => $fsr->getMimeType(),
                    'fsr_size_bytes' => $fsr->getSize(),
                    'total_millicentavos' => static::exactTotal($lineAttributes),
                    'submitted_by_user_id' => $submitter->id,
                    'submitted_at' => now(),
                ]);

                $proposal->lines()->createMany($lineAttributes);

                $lockedECall->update(['status' => ECallStatus::ForEicReview]);

                return $proposal;
            });
        } catch (Throwable $exception) {
            if ($fsrPath !== null) {
                Storage::disk($disk)->delete($fsrPath);
            }

            throw $exception;
        }
    }

    /**
     * @param  list<array{item: PriceListItem, quantity_thousandths: int}>  $lines
     * @return list<array<string, mixed>>
     */
    private static function lineAttributes(array $lines): array
    {
        return array_map(function (array $line): array {
            $item = $line['item'];

            try {
                $lineTotal = BoqQuantity::lineTotalMillicentavos($line['quantity_thousandths'], $item->unit_price_centavos);
            } catch (OverflowException) {
                throw static::tooLargeToStore();
            }

            return [
                'price_list_item_id' => $item->id,
                'code' => $item->code,
                'description' => $item->description,
                'type' => $item->type,
                'unit_of_measure' => $item->unit_of_measure,
                'quantity_thousandths' => $line['quantity_thousandths'],
                'unit_price_centavos' => $item->unit_price_centavos,
                'line_total_millicentavos' => $lineTotal,
            ];
        }, $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $lineAttributes
     */
    private static function exactTotal(array $lineAttributes): int
    {
        try {
            return BoqQuantity::sumMillicentavos(array_column($lineAttributes, 'line_total_millicentavos'));
        } catch (OverflowException) {
            throw static::tooLargeToStore();
        }
    }

    private static function tooLargeToStore(): ValidationException
    {
        return ValidationException::withMessages(['lines' => 'The BOQ total is too large to store exactly.']);
    }
}
