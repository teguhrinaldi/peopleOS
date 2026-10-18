import 'package:connectivity_plus/connectivity_plus.dart';

/// "Online" here means the device has some network transport; reachability of
/// the API itself is discovered by the requests (a failure keeps the
/// outbox pending).
abstract class ConnectivityService {
  Future<bool> get isOnline;
  Stream<bool> get onlineChanges;
}

class PluginConnectivityService implements ConnectivityService {
  PluginConnectivityService([Connectivity? connectivity])
    : _connectivity = connectivity ?? Connectivity();

  final Connectivity _connectivity;

  static bool _online(List<ConnectivityResult> r) =>
      r.any((e) => e != ConnectivityResult.none);

  @override
  Future<bool> get isOnline async =>
      _online(await _connectivity.checkConnectivity());

  @override
  Stream<bool> get onlineChanges =>
      _connectivity.onConnectivityChanged.map(_online).distinct();
}
