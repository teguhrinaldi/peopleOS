import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Keeps queued selfies until they are delivered. Photos never go into the
/// JSON outbox itself; the outbox stores only the returned reference.
abstract class SelfieStore {
  Future<String> save(String key, Uint8List bytes);
  Future<Uint8List?> read(String ref);
  Future<void> delete(String ref);
}

/// Native platforms: files in the app-private support directory.
class FileSelfieStore implements SelfieStore {
  Future<Directory> _dir() async {
    final base = await getApplicationSupportDirectory();
    final dir = Directory('${base.path}/outbox_selfies');
    if (!await dir.exists()) await dir.create(recursive: true);
    return dir;
  }

  @override
  Future<String> save(String key, Uint8List bytes) async {
    final file = File('${(await _dir()).path}/$key.jpg');
    await file.writeAsBytes(bytes, flush: true);
    return file.path;
  }

  @override
  Future<Uint8List?> read(String ref) async {
    final file = File(ref);
    return await file.exists() ? file.readAsBytes() : null;
  }

  @override
  Future<void> delete(String ref) async {
    final file = File(ref);
    if (await file.exists()) await file.delete();
  }
}

/// Web (no file system): base64 in local storage. Selfies are downscaled to
/// ~1024px by the picker so they stay small.
class PrefsSelfieStore implements SelfieStore {
  PrefsSelfieStore(this._prefs);

  final SharedPreferences _prefs;
  static const _prefix = 'outbox.selfie.';

  @override
  Future<String> save(String key, Uint8List bytes) async {
    final ref = '$_prefix$key';
    await _prefs.setString(ref, base64Encode(bytes));
    return ref;
  }

  @override
  Future<Uint8List?> read(String ref) async {
    final raw = _prefs.getString(ref);
    return raw == null ? null : base64Decode(raw);
  }

  @override
  Future<void> delete(String ref) async {
    await _prefs.remove(ref);
  }
}

class MemorySelfieStore implements SelfieStore {
  final Map<String, Uint8List> _files = {};

  @override
  Future<String> save(String key, Uint8List bytes) async {
    _files['mem:$key'] = bytes;
    return 'mem:$key';
  }

  @override
  Future<Uint8List?> read(String ref) async => _files[ref];

  @override
  Future<void> delete(String ref) async => _files.remove(ref);

  int get count => _files.length;
}
