import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/config/app_config.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_text_field.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/peopleos_logo.dart';
import 'package:peopleos/core/widgets/sheets_dialogs.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/core/widgets/language_selector.dart';
import 'package:peopleos/features/demo/demo_data.dart';
import 'package:peopleos/l10n/l10n.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _email;
  final _password = TextEditingController();
  final _server = TextEditingController();

  bool _obscure = true;
  bool _remember = true;
  bool _loading = false;
  bool _editServer = false;
  String? _error;
  bool _noticeResolved = false;
  Map<String, String> _fieldErrors = const {};
  Timer? _lockTimer;
  int _lockSeconds = 0;

  @override
  void initState() {
    super.initState();
    final remembered = ref.read(appSettingsProvider).rememberedEmail;
    _email = TextEditingController(text: remembered ?? '');
    _server.text = ref.read(serverUrlProvider) ?? '';
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_noticeResolved) return;
    _noticeResolved = true;
    final notice = ref.read(authControllerProvider).notice;
    if (notice == AuthNotice.sessionExpired) {
      _error = context.l10n.authSessionExpired;
    }
  }

  @override
  void dispose() {
    _lockTimer?.cancel();
    _email.dispose();
    _password.dispose();
    _server.dispose();
    super.dispose();
  }

  bool get _needsServer =>
      AppConfig.useApi && (ref.read(serverUrlProvider)?.isEmpty ?? true);

  String? _validateEmail(String? v) {
    final value = v?.trim() ?? '';
    if (value.isEmpty) return context.l10n.authEnterEmail;
    if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(value)) {
      return context.l10n.authEnterValidEmail;
    }
    return null;
  }

  String? _validateServer(String? v) {
    final url = ApiClient.normalizeBaseUrl(v);
    if (url == null) return context.l10n.authEnterServer;
    final uri = Uri.tryParse(url);
    if (uri == null || uri.host.isEmpty) {
      return context.l10n.authEnterValidServer;
    }
    return null;
  }

  Future<void> _submit({String? email, String? password}) async {
    if (email != null) _email.text = email;
    if (password != null) _password.text = password;
    setState(() {
      _error = null;
      _fieldErrors = const {};
    });
    if (!_formKey.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    setState(() => _loading = true);
    try {
      if (AppConfig.useApi && (_editServer || _needsServer)) {
        await ref.read(serverUrlProvider.notifier).save(_server.text);
      }
      await ref
          .read(authControllerProvider.notifier)
          .login(
            email: _email.text,
            password: _password.text,
            remember: _remember,
          );
    } on ValidationException catch (e) {
      _showError(
        e.message,
        fields: {
          for (final f in e.fieldErrors.keys) f: e.firstErrorFor(f) ?? '',
        },
      );
    } on RateLimitedException catch (e) {
      _startLock(e.retryAfter.inSeconds);
      _showError(e.message);
    } on UnauthorizedException catch (e) {
      if (!mounted) return;
      _showError(
        e.isBadCredentials ? context.l10n.authBadCredentials : e.message,
      );
    } on ForbiddenException catch (e) {
      if (!mounted) return;
      _showError(_forbiddenCopy(e));
    } on AppException catch (e) {
      _showError(e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _forbiddenCopy(ForbiddenException e) {
    switch (e.code) {
      case 'ACCOUNT_DISABLED':
        return context.l10n.authAccountDisabled;
      case 'NO_EMPLOYEE_PROFILE':
        return context.l10n.authNoEmployeeProfile;
      default:
        return e.message;
    }
  }

  void _showError(String message, {Map<String, String> fields = const {}}) {
    if (!mounted) return;
    setState(() {
      _error = message;
      _fieldErrors = fields;
    });
  }

  void _startLock(int seconds) {
    _lockTimer?.cancel();
    setState(() => _lockSeconds = seconds);
    _lockTimer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return t.cancel();
      setState(() => _lockSeconds--);
      if (_lockSeconds <= 0) t.cancel();
    });
  }

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final version = ref.watch(appVersionProvider);
    final serverUrl = ref.watch(serverUrlProvider);
    final showServerField = AppConfig.useApi && (_needsServer || _editServer);
    final locked = _lockSeconds > 0;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(
              horizontal: PeopleSpacing.page + 4,
              vertical: PeopleSpacing.xl,
            ),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: Breakpoints.formMax),
              child: AutofillGroup(
                child: Form(
                  key: _formKey,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const Align(
                        alignment: Alignment.centerRight,
                        child: LanguageSelector(compact: true),
                      ),
                      const SizedBox(height: PeopleSpacing.md),
                      const Center(child: PeopleOsLogo(size: 44)),
                      const SizedBox(height: PeopleSpacing.xxl),
                      Semantics(
                        header: true,
                        child: Text(
                          context.l10n.authWelcomeBack,
                          style: t.headlineLarge,
                        ),
                      ),
                      const SizedBox(height: PeopleSpacing.xxs),
                      Text(
                        context.l10n.authSignInSubtitle,
                        style: t.bodyLarge!.copyWith(color: c.textMuted),
                      ),
                      const SizedBox(height: PeopleSpacing.xl),
                      if (_error != null) ...[
                        _ErrorBanner(
                          message: locked
                              ? context.l10n.authErrorRetryIn(
                                  _error!,
                                  _lockSeconds,
                                )
                              : _error!,
                        ),
                        const SizedBox(height: PeopleSpacing.md),
                      ],
                      if (AppConfig.useApi) ...[
                        if (showServerField)
                          AppTextField(
                            label: context.l10n.authServerAddress,
                            controller: _server,
                            hint: context.l10n.authServerHint,
                            helper: context.l10n.authServerHelper,
                            prefixIcon: Icons.dns_outlined,
                            keyboardType: TextInputType.url,
                            textInputAction: TextInputAction.next,
                            validator: _validateServer,
                          )
                        else
                          _ServerRow(
                            url: serverUrl ?? '',
                            onChange: () => setState(() => _editServer = true),
                          ),
                        const SizedBox(height: PeopleSpacing.md),
                      ],
                      AppTextField(
                        label: context.l10n.authEmailAddress,
                        controller: _email,
                        hint: context.l10n.authEmailHint,
                        prefixIcon: Icons.mail_outline_rounded,
                        keyboardType: TextInputType.emailAddress,
                        textInputAction: TextInputAction.next,
                        autofillHints: const [AutofillHints.username],
                        validator: _validateEmail,
                        errorText: _fieldErrors['email'],
                      ),
                      const SizedBox(height: PeopleSpacing.md),
                      AppTextField(
                        label: context.l10n.authPassword,
                        controller: _password,
                        hint: context.l10n.authPasswordHint,
                        prefixIcon: Icons.lock_outline_rounded,
                        obscureText: _obscure,
                        textInputAction: TextInputAction.done,
                        autofillHints: const [AutofillHints.password],
                        validator: (v) => (v == null || v.isEmpty)
                            ? context.l10n.authPasswordRequired
                            : null,
                        errorText: _fieldErrors['password'],
                        onSubmitted: (_) => _submit(),
                        suffix: IconButton(
                          tooltip: _obscure
                              ? context.l10n.authShowPassword
                              : context.l10n.authHidePassword,
                          icon: Icon(
                            _obscure
                                ? Icons.visibility_outlined
                                : Icons.visibility_off_outlined,
                          ),
                          onPressed: () => setState(() => _obscure = !_obscure),
                        ),
                      ),
                      const SizedBox(height: PeopleSpacing.xs),
                      _RememberRow(
                        value: _remember,
                        onChanged: (v) => setState(() => _remember = v),
                      ),
                      const SizedBox(height: PeopleSpacing.md),
                      SecondaryButton(
                        label: context.l10n.authSignIn,
                        loading: _loading,
                        onPressed: locked || _loading ? null : _submit,
                      ),
                      Center(
                        child: AppTextButton(
                          label: context.l10n.authForgotPassword,
                          onPressed: () => _showForgot(context),
                        ),
                      ),
                      if (!AppConfig.useApi) ...[
                        const SizedBox(height: PeopleSpacing.md),
                        OutlineButton(
                          label: context.l10n.authDemoAccount,
                          icon: Icons.play_circle_outline_rounded,
                          onPressed: _loading
                              ? null
                              : () => _submit(
                                  email: DemoData.email,
                                  password: DemoData.password,
                                ),
                        ),
                        const SizedBox(height: PeopleSpacing.xs),
                        Text(
                          context.l10n.authDemoNote,
                          textAlign: TextAlign.center,
                          style: t.bodySmall!.copyWith(color: c.textMuted),
                        ),
                      ],
                      const SizedBox(height: PeopleSpacing.xl),
                      Text(
                        context.l10n.authVersion(version),
                        textAlign: TextAlign.center,
                        style: t.bodySmall!.copyWith(color: c.textMuted),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  void _showForgot(BuildContext context) {
    showAppSheet<void>(
      context,
      title: context.l10n.authForgotTitle,
      builder: (context) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            context.l10n.authForgotBody,
            style: Theme.of(context).textTheme.bodyMedium!
                .copyWith(color: context.colors.textSecondary),
          ),
          const SizedBox(height: PeopleSpacing.lg),
          PrimaryButton(
            label: context.l10n.authGotIt,
            onPressed: () => Navigator.of(context).pop(),
          ),
        ],
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    return Semantics(
      liveRegion: true,
      container: true,
      child: Container(
        padding: const EdgeInsets.all(PeopleSpacing.sm + 2),
        decoration: BoxDecoration(
          color: c.errorSoft,
          borderRadius: PeopleRadii.inputRadius,
          border: Border.all(color: c.error.withValues(alpha: 0.3)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.error_outline_rounded, color: c.errorText, size: 20),
            const SizedBox(width: PeopleSpacing.xs),
            Expanded(
              child: Text(
                message,
                style: Theme.of(context).textTheme.bodyMedium!
                    .copyWith(color: c.errorText),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _RememberRow extends StatelessWidget {
  const _RememberRow({required this.value, required this.onChanged});
  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      checked: value,
      label: context.l10n.authRememberMe,
      excludeSemantics: true,
      child: InkWell(
        borderRadius: BorderRadius.circular(8),
        onTap: () => onChanged(!value),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: kMinTap),
          child: Row(
            children: [
              Checkbox(value: value, onChanged: (v) => onChanged(v ?? false)),
              Text(
                context.l10n.authRememberMe,
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ServerRow extends StatelessWidget {
  const _ServerRow({required this.url, required this.onChange});
  final String url;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    return Container(
      padding: const EdgeInsets.only(left: PeopleSpacing.md),
      decoration: BoxDecoration(
        color: c.surfaceMuted,
        borderRadius: PeopleRadii.inputRadius,
        border: Border.all(color: c.border),
      ),
      child: Row(
        children: [
          Icon(Icons.dns_outlined, size: 18, color: c.textMuted),
          const SizedBox(width: PeopleSpacing.xs),
          Expanded(
            child: Text(
              url,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: t.bodySmall!.copyWith(color: c.textSecondary),
            ),
          ),
          AppTextButton(label: context.l10n.authChange, onPressed: onChange),
        ],
      ),
    );
  }
}
