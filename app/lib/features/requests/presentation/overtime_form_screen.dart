import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/core/widgets/app_text_field.dart';
import 'package:peopleos/core/widgets/app_top_bar.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/picker_field.dart';
import 'package:peopleos/core/widgets/sheets_dialogs.dart';
import 'package:peopleos/features/requests/presentation/form_widgets.dart';
import 'package:peopleos/features/requests/presentation/requests_providers.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Ask for approval of extra hours. Approved overtime is a record only; it
/// is not converted to pay.
class OvertimeFormScreen extends ConsumerStatefulWidget {
  const OvertimeFormScreen({super.key});

  @override
  ConsumerState<OvertimeFormScreen> createState() => _OvertimeFormScreenState();
}

class _OvertimeFormScreenState extends ConsumerState<OvertimeFormScreen> {
  final _reason = TextEditingController();
  DateTime? _date;
  TimeOfDay? _start;
  TimeOfDay? _end;
  bool _busy = false;
  String? _error;
  Map<String, String> _errors = const {};

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  DateTime get _today {
    final now = DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date ?? _today,
      firstDate: _today.subtract(const Duration(days: 30)),
      lastDate: _today.add(const Duration(days: 30)),
      helpText: context.l10n.requestsOvertimeDateHelp,
    );
    if (picked != null) setState(() => _date = picked);
  }

  Future<void> _pickTime(bool isStart) async {
    final picked = await showTimePicker(
      context: context,
      initialTime:
          (isStart ? _start : _end) ??
          TimeOfDay(hour: isStart ? 17 : 19, minute: 0),
    );
    if (picked == null) return;
    setState(() => isStart ? _start = picked : _end = picked);
  }

  bool _validate() {
    final l10n = context.l10n;
    final errors = <String, String>{};
    if (_date == null) errors['work_date'] = l10n.requestsValChooseDate;
    if (_start == null) errors['start'] = l10n.requestsValChooseStart;
    if (_end == null) errors['end'] = l10n.requestsValChooseEnd;
    if (_start != null && _end != null) {
      final s = _start!.hour * 60 + _start!.minute;
      final e = _end!.hour * 60 + _end!.minute;
      if (e <= s) errors['end'] = l10n.requestsValEndAfterStart;
    }
    if (_reason.text.trim().length < 5) {
      errors['reason'] = l10n.requestsValReasonWhy;
    }
    setState(() => _errors = errors);
    return errors.isEmpty;
  }

  Future<void> _submit() async {
    setState(() => _error = null);
    if (!_validate()) return;
    setState(() => _busy = true);
    final l10n = context.l10n;
    final offset = ref.read(companyOffsetProvider);
    try {
      await ref
          .read(requestsRepositoryProvider)
          .createOvertime(
            workDate: _date!,
            start: ZonedTime.fromWall(
              _date!,
              _start!.hour,
              _start!.minute,
              offset,
            ),
            end: ZonedTime.fromWall(_date!, _end!.hour, _end!.minute, offset),
            reason: _reason.text.trim(),
          );
      ref.invalidate(overtimeProvider);
      if (!mounted) return;
      context.pop();
      showAppSnack(context, l10n.requestsOvertimeSubmitted);
    } on ValidationException catch (e) {
      setState(() {
        _busy = false;
        _errors = mapServerFieldErrors(e, const {
          'work_date': 'work_date',
          'start_at': 'start',
          'end_at': 'end',
          'reason': 'reason',
        });
        _error = _errors.isEmpty ? describeRequestError(e, l10n) : null;
      });
    } on AppException catch (e) {
      setState(() {
        _busy = false;
        _error = describeRequestError(e, l10n);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final l10n = context.l10n;
    return SubPage(
      title: l10n.requestsNewOvertime,
      bottom: PrimaryButton(
        label: l10n.requestsSubmitRequest,
        loading: _busy,
        onPressed: _busy ? null : _submit,
      ),
      child: ListView(
        padding: const EdgeInsets.all(PeopleSpacing.page),
        children: [
          Text(
            l10n.requestsOvertimeIntro,
            style: Theme.of(context).textTheme.bodyMedium!
                .copyWith(color: c.textSecondary),
          ),
          const SizedBox(height: PeopleSpacing.lg),
          if (_error != null) ...[
            FormErrorBanner(message: _error!),
            const SizedBox(height: PeopleSpacing.md),
          ],
          PickerField(
            label: l10n.requestsDate,
            icon: Icons.calendar_today_outlined,
            value: _date == null ? null : context.fmt.weekdayLong(_date!),
            hint: l10n.requestsChooseDate,
            errorText: _errors['work_date'],
            onTap: _pickDate,
          ),
          const SizedBox(height: PeopleSpacing.md),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: PickerField(
                  label: l10n.requestsStartTime,
                  icon: Icons.schedule_rounded,
                  value: _start?.format(context),
                  hint: l10n.requestsStart,
                  errorText: _errors['start'],
                  onTap: () => _pickTime(true),
                ),
              ),
              const SizedBox(width: PeopleSpacing.sm),
              Expanded(
                child: PickerField(
                  label: l10n.requestsEndTime,
                  icon: Icons.schedule_rounded,
                  value: _end?.format(context),
                  hint: l10n.requestsEnd,
                  errorText: _errors['end'],
                  onTap: () => _pickTime(false),
                ),
              ),
            ],
          ),
          const SizedBox(height: PeopleSpacing.md),
          AppTextField(
            label: l10n.requestsReason,
            controller: _reason,
            hint: l10n.requestsOvertimeReasonHint,
            maxLines: 4,
            minLines: 3,
            maxLength: 500,
            keyboardType: TextInputType.multiline,
            errorText: _errors['reason'],
          ),
        ],
      ),
    );
  }
}
