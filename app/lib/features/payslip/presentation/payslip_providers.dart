import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/utils/paged.dart';
import 'package:peopleos/core/utils/paged_controller.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/features/payslip/domain/payslip.dart';

/// Year filter of the payslip list (null = all).
class PayslipYearNotifier extends Notifier<int?> {
  @override
  int? build() => null;

  void set(int? year) => state = year;
}

final payslipYearProvider = NotifierProvider<PayslipYearNotifier, int?>(
  PayslipYearNotifier.new,
);

class PayslipsController extends PagedNotifier<Payslip> {
  @override
  Future<Paged<Payslip>> fetch(int page) {
    ref.watch(employeeIdProvider);
    return ref
        .read(payslipRepositoryProvider)
        .list(year: ref.read(payslipYearProvider), page: page);
  }

  @override
  Future<PagedState<Payslip>> build() {
    ref.watch(payslipYearProvider);
    ref.watch(employeeIdProvider);
    return super.build();
  }
}

final payslipsProvider =
    AsyncNotifierProvider<PayslipsController, PagedState<Payslip>>(
      PayslipsController.new,
    );

/// Years that have payslips, taken from an unfiltered first page.
final payslipYearsProvider = FutureProvider<List<int>>((ref) async {
  ref.watch(employeeIdProvider);
  final page = await ref.watch(payslipRepositoryProvider).list();
  final years = {for (final p in page.items) ?p.period.year}.toList()
    ..sort((a, b) => b.compareTo(a));
  return years;
});

final payslipDetailProvider = FutureProvider.autoDispose.family<Payslip, int>(
  (ref, id) => ref.watch(payslipRepositoryProvider).detail(id),
);

/// Newest released payslip (for the Home updates strip).
final latestPayslipProvider = FutureProvider<Payslip?>((ref) async {
  ref.watch(employeeIdProvider);
  final page = await ref.watch(payslipRepositoryProvider).list();
  return page.items.firstOrNull;
});

/// Selected payslip in the tablet master-detail layout.
class SelectedPayslipNotifier extends Notifier<int?> {
  @override
  int? build() => null;

  void set(int? id) => state = id;
}

final selectedPayslipProvider = NotifierProvider<SelectedPayslipNotifier, int?>(
  SelectedPayslipNotifier.new,
);
