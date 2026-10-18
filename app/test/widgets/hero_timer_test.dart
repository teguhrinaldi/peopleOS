import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/theme/people_theme.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/effective_today.dart';
import 'package:peopleos/features/home/presentation/widgets/attendance_hero_card.dart';
import 'package:peopleos/l10n/l10n.dart';

import '../helpers/sample_json.dart';

void main() {
  testWidgets('the working timer ticks every second from the check-in time', (
    tester,
  ) async {
    final today = mergeToday(
      server: TodayAttendance.fromJson(
        JsonReader(todayJson(state: 'CHECKED_IN')),
      ),
      items: const [],
      employeeId: 6,
      fetchedAtUtc: DateTime.utc(2026, 9, 30, 7, 35, 11),
    );
    var now = DateTime.utc(2026, 9, 30, 7, 35, 11);

    await tester.pumpWidget(
      MaterialApp(
        theme: PeopleTheme.light,
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        supportedLocales: AppLocalizations.supportedLocales,
        home: Scaffold(
          body: AttendanceHeroCard(
            today: today,
            now: () => now,
            onCheckIn: () {},
            onCheckOut: () {},
            onOpenRequests: () {},
            onOpenSync: () {},
          ),
        ),
      ),
    );
    // Checked in 08:04 (+07) = 01:04Z, now 07:35:11Z -> 6h 31m 11s.
    expect(find.text('06:31:11'), findsOneWidget);

    now = now.add(const Duration(seconds: 2));
    await tester.pump(const Duration(seconds: 1));
    expect(find.text('06:31:13'), findsOneWidget);

    await tester.pumpWidget(const SizedBox());
  });
}
