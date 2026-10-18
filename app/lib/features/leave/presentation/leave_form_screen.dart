import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_text_field.dart';
import 'package:peopleos/core/widgets/app_top_bar.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/list_widgets.dart';
import 'package:peopleos/core/widgets/picker_field.dart';
import 'package:peopleos/core/widgets/sheets_dialogs.dart';
import 'package:peopleos/features/leave/domain/leave_errors.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/features/leave/presentation/leave_providers.dart';
import 'package:peopleos/features/leave/presentation/widgets/leave_preview_card.dart';
import 'package:peopleos/features/leave/presentation/widgets/leave_widgets.dart';
import 'package:peopleos/features/requests/presentation/form_widgets.dart';
import 'package:peopleos/features/requests/presentation/requests_screen.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Create a leave request, or edit a draft ([draft] != null).
class LeaveFormScreen extends ConsumerStatefulWidget {
  const LeaveFormScreen({super.key, this.draft});

  final LeaveRequest? draft;

  @override
  ConsumerState<LeaveFormScreen> createState() => _LeaveFormScreenState();
}

class _LeaveFormScreenState extends ConsumerState<LeaveFormScreen> {
  final _reason = TextEditingController();
  LeaveType? _type;
  DateTime? _start;
  DateTime? _end;
  HalfDay? _half;

  LeavePreview? _preview;
  String? _previewError;
  bool _previewing = false;
  Timer? _debounce;
  int _previewToken = 0;

  bool _saving = false;
  String? _error;
  Map<String, String> _errors = const {};

  bool get _editing => widget.draft != null;

  DateTime get _today {
    final n = DateTime.now();
    return DateTime(n.year, n.month, n.day);
  }

  LeavePolicy? get _policy => _type?.policy;

  @override
  void initState() {
    super.initState();
    final d = widget.draft;
    if (d != null) {
      _start = d.startDate;
      _end = d.endDate;
      _half = d.halfDay;
      _reason.text = d.reason ?? '';
    }
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _reason.dispose();
    super.dispose();
  }

  LeaveDraft? get _draft {
    final type = _type;
    final start = _start;
    final end = _end;
    if (type == null || start == null || end == null) return null;
    return LeaveDraft(
      typeId: type.id,
      start: start,
      end: end,
      halfDay: start == end ? _half : null,
      reason: _reason.text,
    );
  }

  /// Debounced live preview of working days and balance after.
  void _schedulePreview() {
    _debounce?.cancel();
    final draft = _draft;
    if (draft == null) {
      setState(() {
        _preview = null;
        _previewError = null;
      });
      return;
    }
    setState(() => _previewing = true);
    final l10n = context.l10n;
    _debounce = Timer(const Duration(milliseconds: 350), () async {
      final token = ++_previewToken;
      try {
        final p = await ref.read(leaveRepositoryProvider).preview(draft);
        if (!mounted || token != _previewToken) return;
        setState(() {
          _preview = p;
          _previewError = null;
          _previewing = false;
        });
      } on AppException catch (e) {
        if (!mounted || token != _previewToken) return;
        setState(() {
          _preview = null;
          // A missing reason is shown on the field, not as a preview problem.
          _previewError =
              e is ValidationException && e.fieldErrors.containsKey('reason')
              ? null
              : describeLeaveError(e, l10n);
          _previewing = false;
        });
      }
    });
  }

  Future<void> _pickType(
    List<LeaveType> types,
    List<LeaveBalance> balances,
  ) async {
    final picked = await showAppSheet<LeaveType>(
      context,
      title: context.l10n.leaveFormType,
      builder: (sheetContext) => Column(
        children: [
          for (final t in types)
            AppListRow(
              title: t.name,
              subtitle: _typeSubtitle(sheetContext.l10n, t, balances),
              leading: Icon(
                Icons.circle,
                size: 12,
                color: leaveTypeColor(sheetContext, t.ref.color),
              ),
              showChevron: false,
              trailing: t.id == _type?.id
                  ? Icon(
                      Icons.check_circle_rounded,
                      color: sheetContext.colors.primary,
                    )
                  : null,
              onTap: t.policy == null
                  ? null
                  : () => Navigator.of(sheetContext).pop(t),
            ),
        ],
      ),
    );
    if (picked == null) return;
    setState(() {
      _type = picked;
      if (!(picked.policy?.allowHalfDay ?? false)) _half = null;
      _errors = Map.of(_errors)..remove('leave_type_id');
    });
    _schedulePreview();
  }

  String _typeSubtitle(
    AppLocalizations l10n,
    LeaveType t,
    List<LeaveBalance> balances,
  ) {
    if (t.policy == null) return l10n.leaveTypeNotAvailable;
    final b = balances.where((b) => b.type.id == t.id).firstOrNull;
    if (b == null || b.unlimited) {
      return t.ref.isPaid
          ? l10n.leaveBalanceNoLimit
          : l10n.leaveTypeUnpaidNoLimit;
    }
    return l10n.leaveTypeAvailableOf(
      formatDays(l10n, b.available ?? 0),
      formatDays(l10n, b.entitlement ?? 0),
    );
  }

  Future<void> _pickDate({required bool start}) async {
    final maxPast = _policy?.maxPastDays ?? 0;
    final first = _today.subtract(Duration(days: maxPast > 0 ? maxPast : 0));
    final last = DateTime(_today.year + 1, 12, 31);
    final initial = (start ? _start : (_end ?? _start)) ?? _today;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial.isBefore(first) ? first : initial,
      firstDate: start ? first : (_start ?? first),
      lastDate: last,
      helpText: start
          ? context.l10n.leaveFormFirstDayHelp
          : context.l10n.leaveFormLastDayHelp,
    );
    if (picked == null) return;
    setState(() {
      if (start) {
        _start = picked;
        if (_end == null || _end!.isBefore(picked)) _end = picked;
      } else {
        _end = picked;
      }
      if (_start != _end) _half = null;
      _errors = Map.of(_errors)
        ..remove('start_date')
        ..remove('end_date');
    });
    _schedulePreview();
  }

  bool _validate({required bool submit}) {
    final l10n = context.l10n;
    final errors = <String, String>{};
    if (_type == null) errors['leave_type_id'] = l10n.leaveValChooseType;
    if (_start == null) errors['start_date'] = l10n.leaveValChooseFirstDay;
    if (_end == null) errors['end_date'] = l10n.leaveValChooseLastDay;
    if (submit &&
        (_policy?.requiresReason ?? false) &&
        _reason.text.trim().isEmpty) {
      errors['reason'] = l10n.leaveValReasonRequired(_type!.name);
    }
    setState(() => _errors = errors);
    return errors.isEmpty;
  }

  Future<void> _save({required bool submit}) async {
    setState(() => _error = null);
    if (!_validate(submit: submit)) return;
    final draft = _draft!;
    final repo = ref.read(leaveRepositoryProvider);
    final l10n = context.l10n;
    setState(() => _saving = true);
    try {
      if (_editing) {
        await repo.update(widget.draft!.id, draft);
        if (submit) await repo.submit(widget.draft!.id);
      } else {
        await repo.create(draft, submit: submit);
      }
      invalidateLeave(ref);
      if (!mounted) return;
      ref.read(requestsTabProvider.notifier).set(RequestTab.leave);
      context.pop();
      showAppSnack(
        context,
        submit ? l10n.leaveSubmittedSnack : l10n.leaveDraftSaved,
      );
    } on ValidationException catch (e) {
      setState(() {
        _saving = false;
        _errors = mapServerFieldErrors(e, const {
          'leave_type_id': 'leave_type_id',
          'start_date': 'start_date',
          'end_date': 'end_date',
          'half_day': 'half_day',
          'reason': 'reason',
        });
        _error = _errors.isEmpty ? describeLeaveError(e, l10n) : null;
      });
    } on AppException catch (e) {
      setState(() {
        _saving = false;
        _error = describeLeaveError(e, l10n);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;
    final fmt = context.fmt;
    final types = ref.watch(leaveTypesProvider).value ?? const <LeaveType>[];
    final balances =
        ref.watch(leaveBalancesProvider).value ?? const <LeaveBalance>[];

    // Resolve the draft's type once types are loaded.
    if (_type == null && widget.draft != null && types.isNotEmpty) {
      _type = types.where((x) => x.id == widget.draft!.type.id).firstOrNull;
      if (_type != null) {
        WidgetsBinding.instance.addPostFrameCallback((_) => _schedulePreview());
      }
    }
    final policy = _policy;
    final singleDay = _start != null && _start == _end;
    final halfAllowed = (policy?.allowHalfDay ?? false) && singleDay;
    final hints = policy?.hints(l10n) ?? const <String>[];

    return SubPage(
      title: _editing ? l10n.leaveFormEditTitle : l10n.leaveFormTitle,
      bottom: Row(
        children: [
          Expanded(
            child: OutlineButton(
              label: _editing ? l10n.leaveSaveDraft : l10n.leaveSaveAsDraft,
              onPressed: _saving ? null : () => _save(submit: false),
            ),
          ),
          const SizedBox(width: PeopleSpacing.sm),
          Expanded(
            child: PrimaryButton(
              label: l10n.leaveSubmit,
              icon: Icons.send_rounded,
              loading: _saving,
              onPressed: _saving ? null : () => _save(submit: true),
            ),
          ),
        ],
      ),
      child: ListView(
        padding: const EdgeInsets.all(PeopleSpacing.page),
        children: [
          if (_error != null) ...[
            FormErrorBanner(message: _error!),
            const SizedBox(height: PeopleSpacing.md),
          ],
          PickerField(
            label: l10n.leaveFormType,
            icon: Icons.beach_access_outlined,
            value: _type?.name,
            hint: types.isEmpty
                ? l10n.leaveFormTypeLoading
                : l10n.leaveFormTypeHint,
            errorText: _errors['leave_type_id'],
            onTap: types.isEmpty ? () {} : () => _pickType(types, balances),
          ),
          if (hints.isNotEmpty) ...[
            const SizedBox(height: PeopleSpacing.xs),
            Wrap(
              spacing: PeopleSpacing.xs,
              runSpacing: PeopleSpacing.xs,
              children: [
                for (final h in hints)
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 10,
                      vertical: 4,
                    ),
                    decoration: BoxDecoration(
                      color: c.surfaceMuted,
                      borderRadius: PeopleRadii.chipRadius,
                      border: Border.all(color: c.border),
                    ),
                    child: Text(h, style: t.labelMedium),
                  ),
              ],
            ),
          ],
          const SizedBox(height: PeopleSpacing.md),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: PickerField(
                  label: l10n.leaveFormFrom,
                  icon: Icons.calendar_today_outlined,
                  value: _start == null ? null : fmt.weekdayShort(_start!),
                  hint: l10n.leaveFormFirstDay,
                  errorText: _errors['start_date'],
                  onTap: () => _pickDate(start: true),
                ),
              ),
              const SizedBox(width: PeopleSpacing.sm),
              Expanded(
                child: PickerField(
                  label: l10n.leaveFormTo,
                  icon: Icons.event_outlined,
                  value: _end == null ? null : fmt.weekdayShort(_end!),
                  hint: l10n.leaveFormLastDay,
                  errorText: _errors['end_date'],
                  onTap: () => _pickDate(start: false),
                ),
              ),
            ],
          ),
          if (halfAllowed) ...[
            const SizedBox(height: PeopleSpacing.md),
            Text(
              l10n.leaveFormLength,
              style: t.labelLarge!.copyWith(
                color: c.textSecondary,
                fontSize: 13,
              ),
            ),
            const SizedBox(height: 6),
            SegmentedTabs<HalfDay?>(
              value: _half,
              onChanged: (v) {
                setState(() => _half = v);
                _schedulePreview();
              },
              options: {
                null: l10n.leaveFormFullDay,
                HalfDay.am: l10n.leaveFormMorning,
                HalfDay.pm: l10n.leaveFormAfternoon,
              },
            ),
          ],
          if (_errors['half_day'] != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                _errors['half_day']!,
                style: t.bodySmall!.copyWith(color: c.errorText),
              ),
            ),
          const SizedBox(height: PeopleSpacing.md),
          AppTextField(
            label: (policy?.requiresReason ?? false)
                ? l10n.leaveFormReasonRequired
                : l10n.leaveFormReasonOptional,
            controller: _reason,
            hint: l10n.leaveFormReasonHint,
            maxLines: 4,
            minLines: 2,
            maxLength: 500,
            keyboardType: TextInputType.multiline,
            errorText: _errors['reason'],
            onChanged: (_) {
              if (_errors.containsKey('reason')) {
                setState(() => _errors = Map.of(_errors)..remove('reason'));
              }
            },
          ),
          const SizedBox(height: PeopleSpacing.md),
          LeavePreviewCard(
            ready: _draft != null,
            loading: _previewing,
            preview: _preview,
            error: _previewError,
          ),
        ],
      ),
    );
  }
}
