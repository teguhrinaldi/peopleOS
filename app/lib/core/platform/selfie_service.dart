import 'dart:typed_data';

import 'package:image_picker/image_picker.dart';

class SelfiePhoto {
  const SelfiePhoto(this.bytes, {this.fileName = 'selfie.jpg'});
  final Uint8List bytes;
  final String fileName;
}

/// Captures a selfie. Returns null when the user cancels.
abstract class SelfieService {
  Future<SelfiePhoto?> capture();
}

class ImagePickerSelfieService implements SelfieService {
  ImagePickerSelfieService([ImagePicker? picker])
    : _picker = picker ?? ImagePicker();

  final ImagePicker _picker;

  @override
  Future<SelfiePhoto?> capture() async {
    final file = await _picker.pickImage(
      source: ImageSource.camera,
      preferredCameraDevice: CameraDevice.front,
      maxWidth: 1024,
      maxHeight: 1024,
      imageQuality: 82,
    );
    if (file == null) return null;
    return SelfiePhoto(await file.readAsBytes(), fileName: 'selfie.jpg');
  }
}
