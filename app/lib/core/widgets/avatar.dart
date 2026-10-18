import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/utils/formatters.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Circular avatar: network photo when available (with a shimmer while it
/// loads), otherwise initials on a tinted disc. No photo is ever required.
class Avatar extends StatelessWidget {
  const Avatar({super.key, required this.name, this.photoUrl, this.size = 48});

  final String name;
  final String? photoUrl;
  final double size;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final initials = Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [c.primarySoft, c.neutralSoft],
        ),
        border: Border.all(color: c.border),
      ),
      child: Text(
        initialsOf(name),
        style: Theme.of(context).textTheme.titleSmall!.copyWith(
          color: c.primaryText,
          fontSize: size * 0.34,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
    final url = photoUrl;
    return Semantics(
      label: context.l10n.commonProfilePhotoOf(name),
      image: true,
      excludeSemantics: true,
      child: url == null || url.isEmpty
          ? initials
          : ClipOval(
              child: Image.network(
                url,
                width: size,
                height: size,
                fit: BoxFit.cover,
                cacheWidth: (size * MediaQuery.devicePixelRatioOf(context))
                    .round(),
                loadingBuilder: (context, child, progress) => progress == null
                    ? child
                    : Skeleton(width: size, height: size, radius: size / 2),
                errorBuilder: (context, error, stack) => initials,
              ),
            ),
    );
  }
}
