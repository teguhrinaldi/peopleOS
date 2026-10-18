{{-- Upload modal. $employeeId fixes the target employee (employee tab); otherwise HR picks one or leaves it company-wide. --}}
@php
    $fixed = isset($employeeId) && $employeeId;
    $maxKb = (int) config('documents.max_kb', 10240);
    $exts = array_values(array_unique(array_keys((array) config('documents.allowed'))));
    $typeData = $uploadTypes->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'requires_expiry' => $t->requires_expiry, 'visible' => $t->default_visible_to_employee, 'emp' => $t->allow_employee_documents, 'company' => $t->allow_company_documents])->values()->all();
    $open = old('_form') === 'upload';
@endphp
<x-modal name="upload" size="lg" title="Upload document" :description="'PDF, JPG, PNG, DOCX or XLSX, up to '.round($maxKb / 1024).' MB. The file is checked by its content, not only its name.'" :open="$open">
    <form id="upload-form" method="POST" action="{{ route('admin.documents.store') }}" enctype="multipart/form-data" class="space-y-4"
        x-data="fileDrop({ maxBytes: {{ $maxKb * 1024 }}, extensions: @js($exts), types: @js($typeData), employeeFixed: @js((bool) $fixed), initialType: @js((string) old('document_type_id', '')) })">
        @csrf
        <input type="hidden" name="_form" value="upload">
        @if ($fixed)
            <input type="hidden" name="employee_id" value="{{ $employeeId }}">
            <input type="hidden" name="back_to_employee" value="1">
        @endif

        <div>
            <div @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="drop($event)"
                :class="over ? 'border-primary bg-primary-soft' : 'border-line-strong bg-surface-2'"
                class="relative rounded-xl border-2 border-dashed px-4 py-7 text-center transition">
                <x-icon name="upload" class="mx-auto size-6 text-primary-ink" />
                <p class="mt-2 text-[13.5px] text-ink-2"><span class="font-semibold text-primary-ink">Choose a file</span> or drag it here</p>
                <input x-ref="input" id="f-file" type="file" name="file" accept=".{{ implode(',.', $exts) }}" required
                    class="absolute inset-0 size-full cursor-pointer opacity-0" aria-label="File to upload" aria-describedby="f-file-msg" @change="pick($event.target.files)">
            </div>
            <p x-show="file" x-cloak class="mt-2 flex items-center gap-2 text-[13px] text-ink-2"><x-icon name="paperclip" class="size-4" /><span class="min-w-0 truncate font-medium text-ink" x-text="file?.name"></span><span class="text-ink-3" x-text="size()"></span></p>
            <p id="f-file-msg" x-show="error" x-cloak class="mt-2 text-xs font-medium text-danger-ink" role="alert" x-text="error"></p>
            @error('file')<p class="mt-2 text-xs font-medium text-danger-ink" role="alert">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @unless ($fixed)
                <div class="sm:col-span-2">
                    <label for="f-employee_id" class="mb-1.5 block text-[13px] font-medium text-ink">Belongs to</label>
                    <x-listbox><select id="f-employee_id" name="employee_id" class="ctl" x-model="employeeId" @error('employee_id') aria-invalid="true" @enderror>
                        <option value="">Company document (not tied to an employee)</option>
                        @foreach ($employees as $id => $label)<option value="{{ $id }}" @selected((string) old('employee_id') === (string) $id)>{{ $label }}</option>@endforeach
                    </select></x-listbox>
                    @error('employee_id')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
                </div>
            @endunless
            <div>
                <label for="f-document_type_id" class="mb-1.5 block text-[13px] font-medium text-ink">Document type <span class="text-danger">*</span></label>
                <x-listbox><select id="f-document_type_id" name="document_type_id" class="ctl" required x-model="typeId" @error('document_type_id') aria-invalid="true" @enderror>
                    <option value="">Choose a type</option>
                    <template x-for="t in allowedTypes" :key="t.id"><option :value="t.id" x-text="t.name" :selected="String(t.id) === String(typeId)"></option></template>
                </select></x-listbox>
                @error('document_type_id')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="f-expiry_date" class="mb-1.5 block text-[13px] font-medium text-ink">Expiry date <span class="text-danger" x-show="type?.requires_expiry" x-cloak>*</span></label>
                <input id="f-expiry_date" type="date" name="expiry_date" value="{{ old('expiry_date') }}" class="ctl" :required="!!type?.requires_expiry" @error('expiry_date') aria-invalid="true" @enderror>
                <p class="mt-1.5 text-xs text-ink-3" x-show="type?.requires_expiry" x-cloak>This document type needs an expiry date.</p>
                @error('expiry_date')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-2">
                <label for="f-title" class="mb-1.5 block text-[13px] font-medium text-ink">Title <span class="text-danger">*</span></label>
                <input id="f-title" x-ref="title" name="title" value="{{ old('title') }}" maxlength="150" required class="ctl" @error('title') aria-invalid="true" @enderror>
                @error('title')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-2" x-show="hasEmployee">
                <label class="flex cursor-pointer items-center gap-2.5 text-[13.5px] text-ink">
                    <input type="hidden" name="visible_to_employee" value="0">
                    <input type="checkbox" name="visible_to_employee" value="1" class="size-4 accent-[var(--primary)]" @checked(old('visible_to_employee', '1') !== '0') :checked="type ? type.visible : true">
                    Visible to the employee in the mobile app
                </label>
            </div>
            <x-textarea name="notes" label="Notes" rows="2" maxlength="1000" class="sm:col-span-2" />
        </div>
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Cancel</x-button>
        <x-button type="submit" form="upload-form" icon="upload">Upload</x-button>
    </x-slot:footer>
</x-modal>
