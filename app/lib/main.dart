import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:peopleos/app/app.dart';
import 'package:peopleos/app/di.dart';
import 'package:shared_preferences/shared_preferences.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting();
  final prefs = await SharedPreferences.getInstance();
  final info = await PackageInfo.fromPlatform();
  runApp(
    ProviderScope(
      // Failed loads surface as error states with a manual retry instead of
      // silent automatic retries.
      retry: (retryCount, error) => null,
      overrides: [
        sharedPreferencesProvider.overrideWithValue(prefs),
        appVersionProvider.overrideWithValue(info.version),
      ],
      child: const PeopleOsApp(),
    ),
  );
}
