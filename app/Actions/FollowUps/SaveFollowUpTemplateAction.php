<?php

namespace App\Actions\FollowUps;

use App\Enums\FollowUpPriority;
use App\Models\FollowUpTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FollowUps\FollowUpAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * W3 organization follow-up templates: managed by follow_ups.manage_all,
 * usable by everyone who may create follow-ups. A template only pre-fills the
 * manual create form; the follow-up itself is still created (and validated)
 * by SaveFollowUpAction, so it can never bypass tenant, store, assignment or
 * link rules. Deactivation (`active = false`) hides it from the form.
 */
class SaveFollowUpTemplateAction
{
    public function __construct(
        private readonly FollowUpAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(Organization $organization, bool $creating, ?int $ignoreId = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120', Rule::unique('follow_up_templates', 'name')
                ->where('organization_id', $organization->getKey())->ignore($ignoreId)],
            'title' => [$required, 'string', 'max:200'],
            'default_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'default_priority' => ['sometimes', Rule::enum(FollowUpPriority::class)],
            'default_due_offset_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'default_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, Organization $organization, array $data): FollowUpTemplate
    {
        $this->access->authorize($this->access->canManageAll($actor, $organization));
        $data = Validator::make($data, self::rules($organization, true))->validate();

        return DB::transaction(function () use ($actor, $organization, $data) {
            $template = new FollowUpTemplate;
            $template->organization_id = $organization->getKey();
            $template->created_by_user_id = $actor->getKey();
            $template->default_priority = FollowUpPriority::Normal;
            $template->active = true;
            $this->fill($template, $data);
            $template->save();
            $this->audit->record('follow_up_template.created', $actor, $organization, null, $template, newValues: $this->snapshot($template));

            return $template;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Organization $organization, FollowUpTemplate $template, array $data): FollowUpTemplate
    {
        $this->access->authorize($this->access->canManageAll($actor, $organization));
        $data = Validator::make($data, self::rules($organization, false, (int) $template->getKey()))->validate();

        return DB::transaction(function () use ($actor, $organization, $template, $data) {
            $locked = FollowUpTemplate::query()->where('organization_id', $organization->getKey())->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);
            $this->fill($locked, $data);
            $after = $this->snapshot($locked);
            if ($before === $after) {
                return $locked;
            }
            $locked->save();
            $this->audit->record('follow_up_template.updated', $actor, $organization, null, $locked,
                oldValues: array_diff_assoc($before, $after), newValues: array_diff_assoc($after, $before));

            return $locked;
        });
    }

    /** @param array<string, mixed> $data */
    private function fill(FollowUpTemplate $template, array $data): void
    {
        $text = fn ($value) => trim((string) $value) === '' ? null : trim((string) $value);
        if (array_key_exists('name', $data)) $template->name = trim((string) $data['name']);
        if (array_key_exists('title', $data)) $template->title = trim((string) $data['title']);
        if (array_key_exists('default_note', $data)) $template->default_note = $text($data['default_note']);
        if (array_key_exists('default_priority', $data)) $template->default_priority = FollowUpPriority::from((string) $data['default_priority']);
        if (array_key_exists('default_due_offset_days', $data)) $template->default_due_offset_days = $data['default_due_offset_days'] === null ? null : (int) $data['default_due_offset_days'];
        if (array_key_exists('default_time', $data)) $template->default_time = $text($data['default_time']);
        if (array_key_exists('active', $data)) $template->active = (bool) $data['active'];
    }

    /** @return array<string, scalar|null> */
    private function snapshot(FollowUpTemplate $template): array
    {
        return [
            'name' => $template->name, 'title' => $template->title, 'default_note' => $template->default_note,
            'default_priority' => $template->default_priority?->value, 'default_due_offset_days' => $template->default_due_offset_days,
            'default_time' => $template->default_time, 'active' => (bool) $template->active,
        ];
    }
}
