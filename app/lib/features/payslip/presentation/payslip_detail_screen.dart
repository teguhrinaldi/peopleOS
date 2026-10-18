import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_top_bar.dart';
import 'package:peopleos/l10n/l10n.dart';
import 'package:peopleos/features/payslip/presentation/widgets/payslip_detail_view.dart';

/// Phone page / notification deep link target for one payslip.
class PayslipDetailScreen extends StatelessWidget {
  const PayslipDetailScreen({super.key, required this.id});

  final int id;

  @override
  Widget build(BuildContext context) {
    return SubPage(
      title: context.l10n.payslipDetailTitle,
      child: ListView(
        padding: const EdgeInsets.all(PeopleSpacing.page),
        children: [PayslipDetailView(id: id)],
      ),
    );
  }
}
