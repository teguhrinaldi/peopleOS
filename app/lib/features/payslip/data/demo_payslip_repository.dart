import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/paged.dart';
import 'package:peopleos/features/demo/demo_backend.dart';
import 'package:peopleos/features/payslip/domain/payslip.dart';

class DemoPayslipRepository implements PayslipRepository {
  DemoPayslipRepository(this._backend);

  final DemoBackend _backend;

  @override
  Future<Paged<Payslip>> list({int? year, int page = 1}) async {
    final res = await _backend.payslips(year, page);
    return Paged(
      items: (res['data'] as List)
          .map((e) => Payslip.fromJson(JsonReader.of(e)))
          .toList(),
      page: res['page'] as int,
      lastPage: res['last_page'] as int,
      total: res['total'] as int,
    );
  }

  @override
  Future<Payslip> detail(int id) async =>
      Payslip.fromJson(JsonReader.of(await _backend.payslipDetail(id)));

  @override
  Future<DownloadedFile> pdf(Payslip p) async => DownloadedFile(
    bytes: await _backend.payslipPdf(p.id),
    fileName: '${p.number}.pdf',
  );
}
