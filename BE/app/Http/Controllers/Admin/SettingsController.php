<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GeneralSettingsRequest;
use App\Models\AttendancePolicy;
use App\Services\Admin\SettingsService;
use App\Support\CompanyContext;
use App\Support\Currencies;
use App\Support\DateDisplay;
use App\Support\Settings;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(CompanyContext $context, Settings $settings): View
    {
        return view('admin.settings.general', [
            'company' => $context->company(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'now' => $context->now(),
            'values' => $settings->effective(),
            'currencies' => Currencies::options(),
            'dateFormats' => DateDisplay::FORMATS,
            'defaultPolicy' => AttendancePolicy::query()->where('company_id', $context->id())->where('is_default', true)->first(),
            'exampleNumber' => $settings->nextEmployeeNumber(42),
        ]);
    }

    public function update(GeneralSettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        $settings->updateSettings($request->typedSettings(), $request->user());
        $settings->updateGeneral($request->settings(), $request->file('logo'), $request->boolean('remove_logo'), $request->user());

        return redirect()->route('admin.settings')->with('success', 'Settings saved.');
    }

    public function attendance(Request $request, SettingsService $settings): RedirectResponse
    {
        $required = (bool) $request->validate(['require_selfie' => ['required', 'boolean']])['require_selfie'];
        $settings->setDefaultSelfieRequired($required, $request->user());

        return redirect(route('admin.settings').'#attendance')->with('success', $required
            ? 'Selfies are now required at locations that use the default policy.'
            : 'Selfies are no longer required at locations that use the default policy.');
    }

    public function roles(SettingsService $settings): View
    {
        return view('admin.settings.roles', $settings->roleMatrix());
    }
}
