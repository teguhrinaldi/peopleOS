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

/// Ask for a correction of a day's check-in / check-out (last 30 days).
class CorrectionFormScreen extends ConsumerStatefulWidget {
  const CorrectionFormScreen({super.key, this.initialDate});

  final DateTime? initialDate;

  @override
  ConsumerState<CorrectionFormScreen> createState() =>
      _CorrectionFormScreenState();
}

class _CorrectionFormScreenState extends ConsumerState<CorrectionFormScreen> {
  final _reason = TextEditingController();
  DateTime? _date;
  TimeOfDay? _in;
  TimeOfDay? _out;
  bool _busy = false;
  String? _error;
  Map<String, String> _errors = const {};

  @override
  void initState() {
    super.initState();
    _date = widget.initialDate;
  }

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
      lastDate: _today,
      helpText: context.l10n.requestsCorrectionDateHelp,
    );
    if (picked != null) setState(() => _date = picked);
  }

  Future<void> _pickTime(bool isIn) async {
    final picked = await showTimePicker(
      context: context,
      initialTime:
          (isIn ? _in : _out) ?? TimeOfDay(hour: isIn ? 8 : 17, minute: 0),
    );
    if (picked != null) setState(() => isIn ? _in = picked : _out = picked);
  }

  bool _validate() {
    final l10n = context.l10n;
    final errors = <String, String>{};
    if (_date == null) errors['work_date'] = l10n.requestsValChooseWorkDate;
    if (_in == null && _out == null) {
      errors['times'] = l10n.requestsValTimes;
    }
    final reason = _reason.text.trim();
    if (reason.length < 5) {
      errors['reason'] = l10n.requestsValReasonWhatHappened;
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
    ZonedTime? at(TimeOfDay? t) =>
        t == null ? null : ZonedTime.fromWall(_date!, t.hour, t.minute, offset);
    try {
      await ref
          .read(requestsRepositoryProvider)
          .createCorrection(
            workDate: _date!,
            checkIn: at(_in),
            checkOut: at(_out),
            reason: _reason.text.trim(),
          );
      ref.invalidate(correctionsProvider);
      if (!mounted) return;
      context.pop();
      showAppSnack(context, l10n.requestsCorrectionSubmitted);
    } on ValidationException catch (e) {
      setState(() {
        _busy = false;
        _errors = mapServerFieldErrors(e, const {
          'work_date': 'work_date',
          'requested_check_in_at': 'times',
          'requested_check_out_at': 'times',
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
      title: l10n.requestsNewCorrection,
      bottom: PrimaryButton(
        label: l10n.requestsSubmitRequest,
        loading: _busy,
        onPressed: _busy ? null : _submit,
      ),
      child: ListView(
        padding: const EdgeInsets.all(PeopleSpacing.page),
        children: [
          Text(
            l10n.requestsCorrectionIntro,
            style: Theme.of(context).textTheme.bodyMedium!
                .copyWith(color: c.textSecondary),
          ),
          const SizedBox(height: PeopleSpacing.lg),
          if (_error != null) ...[
            FormErrorBanner(message: _error!),
            const SizedBox(height: PeopleSpacing.md),
          ],
          PickerField(
            label: l10n.requestsDetailWorkDate,
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
                  label: l10n.requestsCheckInTime,
                  icon: Icons.login_rounded,
                  value: _in?.format(context),
                  hint: l10n.requestsOptional,
                  onTap: () => _pickTime(true),
                  onClear: () => setState(() => _in = null),
                ),
              ),
              const SizedBox(width: PeopleSpacing.sm),
              Expanded(
                child: PickerField(
                  label: l10n.requestsCheckOutTime,
                  icon: Icons.logout_rounded,
                  value: _out?.format(context),
                  hint: l10n.requestsOptional,
                  onTap: () => _pickTime(false),
                  onClear: () => setState(() => _out = null),
                ),
              ),
            ],
          ),
          if (_errors['times'] != null)
            Padding(
              padding: const EdgeInsets.only(top: 6, left: 4),
              child: Text(
                _errors['times']!,
                style: Theme.of(context).textTheme.bodySmall!
                    .copyWith(color: c.errorText),
              ),
            ),
          const SizedBox(height: PeopleSpacing.md),
          AppTextField(
            label: l10n.requestsReason,
            controller: _reason,
            hint: l10n.requestsCorrectionReasonHint,
            maxLines: 4,
            minLines: 3,
            maxLength: 500,
            textInputAction: TextInputAction.newline,
            keyboardType: TextInputType.multiline,
            errorText: _errors['reason'],
          ),
        ],
      ),
    );
  }
}
