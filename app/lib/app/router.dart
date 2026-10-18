import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/app_shell.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/attendance/presentation/attendance_screen.dart';
import 'package:peopleos/features/attendance/presentation/check_flow_screen.dart';
import 'package:peopleos/features/attendance/presentation/sync_center_screen.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/features/auth/presentation/login_screen.dart';
import 'package:peopleos/features/auth/presentation/splash_screen.dart';
import 'package:peopleos/features/common/presentation/coming_soon_screen.dart';
import 'package:peopleos/features/home/presentation/home_screen.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/features/leave/presentation/leave_detail.dart';
import 'package:peopleos/features/leave/presentation/leave_form_screen.dart';
import 'package:peopleos/features/notifications/presentation/notifications_screen.dart';
import 'package:peopleos/features/documents/presentation/document_detail_screen.dart';
import 'package:peopleos/features/documents/presentation/documents_screen.dart';
import 'package:peopleos/features/payslip/presentation/payslip_detail_screen.dart';
import 'package:peopleos/features/payslip/presentation/payslip_screen.dart';
import 'package:peopleos/features/profile/presentation/profile_detail_screen.dart';
import 'package:peopleos/features/profile/presentation/profile_screen.dart';
import 'package:peopleos/features/profile/presentation/settings_screen.dart';
import 'package:peopleos/features/requests/presentation/correction_form_screen.dart';
import 'package:peopleos/features/requests/presentation/overtime_form_screen.dart';
import 'package:peopleos/features/requests/presentation/requests_screen.dart';

final _rootKey = GlobalKey<NavigatorState>(debugLabel: 'root');

/// Route paths (kept in one place so screens never hardcode strings).
class Routes {
  const Routes._();
  static const splash = '/splash';
  static const login = '/login';
  static const home = '/home';
  static const attendance = '/attendance';
  static const requests = '/requests';
  static const payslip = '/payslip';
  static const profile = '/profile';
  static const notifications = '/notifications';
  static const settings = '/settings';
  static const sync = '/sync';
  static const newCorrection = '/requests/new-correction';
  static const newOvertime = '/requests/new-overtime';
  static const newLeave = '/leave/new';
  static String leaveDetail(int id) => '/leave/$id';
  static String check(CheckType type) =>
      '/check/${type == CheckType.checkIn ? 'in' : 'out'}';
  static String soon(String feature) => '/soon/$feature';
  static String profileDetail(String kind) => '/profile-detail/$kind';
  static String payslipDetail(int id) => '/payslip-detail/$id';
  static const documents = '/documents';
  static String documentDetail(int id) => '/documents/$id';
}

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier<int>(0);
  ref.listen(authControllerProvider, (_, _) => refresh.value++);
  ref.onDispose(refresh.dispose);

  return GoRouter(
    navigatorKey: _rootKey,
    initialLocation: Routes.splash,
    refreshListenable: refresh,
    redirect: (context, state) {
      final status = ref.read(authControllerProvider).status;
      final loc = state.matchedLocation;
      switch (status) {
        case AuthStatus.unknown:
          return loc == Routes.splash ? null : Routes.splash;
        case AuthStatus.signedOut:
          return loc == Routes.login ? null : Routes.login;
        case AuthStatus.signedIn:
          return (loc == Routes.login || loc == Routes.splash)
              ? Routes.home
              : null;
      }
    },
    routes: [
      GoRoute(path: Routes.splash, builder: (_, _) => const SplashScreen()),
      GoRoute(path: Routes.login, builder: (_, _) => const LoginScreen()),
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => AppShell(shell: shell),
        branches: [
          StatefulShellBranch(
            routes: [
              GoRoute(path: Routes.home, builder: (_, _) => const HomeScreen()),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: Routes.attendance,
                builder: (_, _) => const AttendanceScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: Routes.requests,
                builder: (_, _) => const RequestsScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: Routes.payslip,
                builder: (_, _) => const PayslipScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: Routes.profile,
                builder: (_, _) => const ProfileScreen(),
              ),
            ],
          ),
        ],
      ),
      GoRoute(
        path: '/check/:kind',
        parentNavigatorKey: _rootKey,
        builder: (_, state) => CheckFlowScreen(
          type: state.pathParameters['kind'] == 'out'
              ? CheckType.checkOut
              : CheckType.checkIn,
        ),
      ),
      GoRoute(
        path: Routes.newCorrection,
        parentNavigatorKey: _rootKey,
        builder: (_, state) => CorrectionFormScreen(
          initialDate: state.extra is DateTime
              ? state.extra! as DateTime
              : null,
        ),
      ),
      GoRoute(
        path: Routes.newOvertime,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const OvertimeFormScreen(),
      ),
      GoRoute(
        path: Routes.newLeave,
        parentNavigatorKey: _rootKey,
        builder: (_, state) => LeaveFormScreen(
          draft: state.extra is LeaveRequest
              ? state.extra! as LeaveRequest
              : null,
        ),
      ),
      GoRoute(
        path: '/leave/:id',
        parentNavigatorKey: _rootKey,
        builder: (_, state) =>
            LeaveDetailScreen(id: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(
        path: Routes.notifications,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const NotificationsScreen(),
      ),
      GoRoute(
        path: Routes.settings,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const SettingsScreen(),
      ),
      GoRoute(
        path: Routes.sync,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const SyncCenterScreen(),
      ),
      GoRoute(
        path: '/soon/:feature',
        parentNavigatorKey: _rootKey,
        builder: (_, state) =>
            ComingSoonScreen(feature: state.pathParameters['feature']!),
      ),
      GoRoute(
        path: '/profile-detail/:kind',
        parentNavigatorKey: _rootKey,
        builder: (_, state) =>
            ProfileDetailScreen(kind: state.pathParameters['kind']!),
      ),
      GoRoute(
        path: '/payslip-detail/:id',
        parentNavigatorKey: _rootKey,
        builder: (_, state) =>
            PayslipDetailScreen(id: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(
        path: Routes.documents,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const DocumentsScreen(),
      ),
      GoRoute(
        path: '/documents/:id',
        parentNavigatorKey: _rootKey,
        builder: (_, state) =>
            DocumentDetailScreen(id: int.parse(state.pathParameters['id']!)),
      ),
    ],
  );
});
