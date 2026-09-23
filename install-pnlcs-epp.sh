#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

VERSION='1.1.0'
# Reviewed PNLCS module source. Keep the input immutable, as in the WHMCS installer.
# Change VERSION and SOURCE_COMMIT together when updating the distributed module.
SOURCE_COMMIT='8620b0c4ecafb16cffc468b459c2885c384dc833'
ARCHIVE="pnlcs-epp-registrar-${VERSION}.tar.gz"
DOWNLOAD_URL="https://github.com/getnamingo/pnlcs-epp-registrar/archive/${SOURCE_COMMIT}.tar.gz"

CC_REGISTRIES=(
  registrebf switch niccl cocca cocca2 eurid afnic nicge carnet nicim switchli
  niclv nicmx sidn iisnu nask rotld iis hostmaster ye zadna
)
G_REGISTRIES=(
  central core dns godaddy google hello identity org itcom namingo regtons ryce
  tucows verisign zdns
)
R_REGISTRIES=(drsua ukrnames)

usage() {
  cat <<EOF_USAGE
Usage:
  $(basename "$0") <registry> [pnlcs_path]

Examples:
  $(basename "$0") namingo
  $(basename "$0") namingo /var/www/pnlcs

Supported registry profiles:
  cc: $(printf '%s ' "${CC_REGISTRIES[@]}")
  g:  $(printf '%s ' "${G_REGISTRIES[@]}")
  r:  $(printf '%s ' "${R_REGISTRIES[@]}")
EOF_USAGE
}

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

info() {
  printf '%s\n' "$*"
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

is_supported_registry() {
  local wanted=$1 item
  for item in "${CC_REGISTRIES[@]}" "${G_REGISTRIES[@]}" "${R_REGISTRIES[@]}"; do
    [[ "$item" == "$wanted" ]] && return 0
  done
  return 1
}

is_pnlcs_root() {
  local path=${1%/}
  [[ -f "$path/artisan" && -f "$path/bootstrap/app.php" \
    && -f "$path/app/Contracts/RegistrarModuleInterface.php" \
    && -d "$path/modules/Registrars" ]]
}

find_pnlcs_root() {
  local found=() artisan root item seen

  while IFS= read -r artisan; do
    root=${artisan%/artisan}
    is_pnlcs_root "$root" || continue

    seen=0
    for item in "${found[@]:-}"; do
      [[ "$item" == "$root" ]] && seen=1 && break
    done
    ((seen == 0)) && found+=("$root")
  done < <(find /var/www -maxdepth 5 -type f -name artisan -print 2>/dev/null || true)

  if ((${#found[@]} == 1)); then
    printf '%s\n' "${found[0]}"
    return 0
  fi

  return 1
}

prompt_pnlcs_root() {
  local path
  while true; do
    if [[ -t 0 || -t 1 || -t 2 ]]; then
      read -r -p 'PNLCS path: ' path </dev/tty
    else
      die 'PNLCS was not detected under /var/www and no interactive terminal is available.'
    fi

    path=${path%/}
    if is_pnlcs_root "$path"; then
      printf '%s\n' "$path"
      return 0
    fi

    printf 'Not a valid PNLCS root: %s (expected artisan, bootstrap/app.php, the registrar contract and modules/Registrars)\n' "$path" >&2
  done
}

ask_yes_no() {
  local prompt=$1 answer

  [[ -t 0 || -t 1 || -t 2 ]] || return 1

  while true; do
    read -r -p "$prompt [y/N]: " answer </dev/tty
    case "${answer,,}" in
      y|yes) return 0 ;;
      ''|n|no) return 1 ;;
      *) printf 'Please answer yes or no.\n' >&2 ;;
    esac
  done
}

if (($# == 0)); then
  usage
  exit 0
fi

if [[ "${1:-}" == '-h' || "${1:-}" == '--help' ]]; then
  usage
  exit 0
fi

(($# <= 2)) || die 'Too many arguments.'

registry=${1,,}
is_supported_registry "$registry" || {
  usage >&2
  die "Unsupported registry profile: $registry"
}

# Equivalent to PHP ucfirst() after normalizing the profile name to lowercase.
registry_name="${registry^}"

need_cmd curl
need_cmd tar
need_cmd grep
need_cmd find
need_cmd perl
need_cmd mktemp
need_cmd php
need_cmd composer
perl -MJSON::PP -e 1 >/dev/null 2>&1 || die 'Perl JSON::PP module is required.'
id www-data >/dev/null 2>&1 || die 'Required web-server user not found: www-data'

if [[ ${EUID} -eq 0 ]]; then
  SUDO=()
else
  need_cmd sudo
  SUDO=(sudo)
fi

if (($# == 2)); then
  pnlcs_path=${2%/}
  is_pnlcs_root "$pnlcs_path" || die "Invalid PNLCS path: $pnlcs_path"
else
  if pnlcs_path=$(find_pnlcs_root); then
    info "Detected PNLCS: $pnlcs_path"
  else
    info 'PNLCS was not uniquely detected under /var/www.'
    pnlcs_path=$(prompt_pnlcs_root)
  fi
fi

registrars_dir="$pnlcs_path/modules/Registrars"
# Directory/namespace/class casing must agree for PNLCS PSR-4 discovery on Linux.
module_dest="$registrars_dir/$registry_name"
[[ ! -L "$module_dest" ]] || die "Refusing to replace symlinked module directory: $module_dest"

workdir=$(mktemp -d -t namingo-pnlcs-epp.XXXXXXXX)
stage_owned='no'
cleanup() {
  if [[ "$stage_owned" == 'yes' && -d "${stage_dest:-}" ]]; then
    "${SUDO[@]}" rm -rf -- "$stage_dest"
  fi
  rm -rf -- "$workdir"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

archive_path="$workdir/$ARCHIVE"
extract_dir="$workdir/extracted"
mkdir -p "$extract_dir"

info "Downloading Namingo PNLCS EPP module v${VERSION}..."
curl --fail --location --silent --show-error \
  --retry 3 --retry-delay 1 --retry-all-errors \
  --proto '=https' --tlsv1.2 \
  --output "$archive_path" "$DOWNLOAD_URL"

# The URL is commit-pinned. Also reject path traversal before extraction.
tar -tzf "$archive_path" > "$workdir/archive-files"
if grep -Eq '(^/|(^|/)\.\.(/|$))' "$workdir/archive-files"; then
  die 'Archive contains an unsafe path.'
fi

tar -xzf "$archive_path" -C "$extract_dir"

mapfile -t module_candidates < <(
  find "$extract_dir" -mindepth 3 -maxdepth 3 -type f -name EppRegistrar.php -printf '%h\n' | sort -u
)
((${#module_candidates[@]} == 1)) || die 'Unexpected archive structure: could not uniquely locate EPP/EppRegistrar.php.'
module_dir=${module_candidates[0]}
[[ -f "$module_dir/../LICENSE" ]] && cp -- "$module_dir/../LICENSE" "$module_dir/LICENSE"

[[ -f "$module_dir/EppRegistrar.php" ]] || die 'Unexpected archive structure: missing EppRegistrar.php.'
[[ -f "$module_dir/pnlcs.json" ]] || die 'Unexpected archive structure: missing pnlcs.json.'
[[ -f "$module_dir/namingo/composer.json" && -f "$module_dir/namingo/composer.lock" ]] \
  || die 'Unexpected archive structure: missing module-local Composer manifest/lockfile.'

# Customize only the registrar's PHP. Tembo and its dependencies keep their names.
customizer="$workdir/customize-php.pl"
cat > "$customizer" <<'PERL'
use strict;
use warnings;

my $registry      = $ENV{'REGISTRY'}      // die "REGISTRY missing\n";
my $registry_name = $ENV{'REGISTRY_NAME'} // die "REGISTRY_NAME missing\n";

for my $path (@ARGV) {
    open my $in, '<', $path or die "Cannot read $path: $!\n";
    local $/;
    my $content = <$in>;
    close $in;

    $content =~ s/Modules\\Registrars\\EPP\b/Modules\\Registrars\\${registry_name}/g;
    $content =~ s/\bEppRegistrar\b/${registry_name}Registrar/g;
    # getModuleName(), registrar_settings lookups, domain writes and lookup results.
    $content =~ s/'epp'/'${registry}'/g;
    $content =~ s{'logs/epp'}{'logs/${registry}'}g;
    $content =~ s{storage/logs/epp}{storage/logs/${registry}}g;
    $content =~ s{modules/Registrars/EPP/}{modules/Registrars/${registry_name}/}g;
    $content =~ s/'pnlcs'/'${registry}'/g;
    $content =~ s/Connect PNLCS to any domain registry using the standard EPP protocol\./PNLCS EPP integration for the ${registry_name} registry./g;
    $content =~ s/"EPP \{\$operation\}/"${registry_name} EPP {\$operation}/g;
    $content =~ s/Release archives include Tembo/The installer installs Tembo/g;
    $content =~ s/Install a release archive or run/Run the installer again or run/g;

    open my $out, '>', $path or die "Cannot write $path: $!\n";
    print {$out} $content;
    close $out or die "Cannot close $path: $!\n";
}
PERL

mapfile -d '' php_files < <(
  find "$module_dir" -type f -name '*.php' ! -path "$module_dir/namingo/*" -print0
)
((${#php_files[@]} > 0)) || die 'Unexpected archive structure: no customizable PHP files found.'
REGISTRY="$registry" REGISTRY_NAME="$registry_name" perl "$customizer" "${php_files[@]}"

# The manifest class must match both the PHP namespace and the PSR-4 file path.
json_path="$module_dir/pnlcs.json"
REGISTRY="$registry" REGISTRY_NAME="$registry_name" perl -MJSON::PP -e '
  use strict; use warnings;
  my ($path) = @ARGV;
  open my $fh, "<", $path or die "Cannot read $path: $!\n";
  local $/; my $raw = <$fh>; close $fh;
  my $data = decode_json($raw);
  $data->{name} = $ENV{REGISTRY};
  $data->{class} = "Modules\\Registrars\\$ENV{REGISTRY_NAME}\\$ENV{REGISTRY_NAME}Registrar";
  $data->{display_name} = "$ENV{REGISTRY_NAME} EPP Module";
  $data->{description} = "PNLCS EPP integration for the $ENV{REGISTRY_NAME} registry.";
  open my $out, ">", $path or die "Cannot write $path: $!\n";
  print {$out} JSON::PP->new->pretty->encode($data);
  close $out or die "Cannot close $path: $!\n";
' "$json_path"

mv -- "$module_dir/EppRegistrar.php" "$module_dir/${registry_name}Registrar.php"
main_file="$module_dir/${registry_name}Registrar.php"
grep -Fq "namespace Modules\\Registrars\\${registry_name};" "$main_file" \
  || die 'Failed to customize the registrar namespace.'
grep -Fq "final class ${registry_name}Registrar " "$main_file" \
  || die 'Failed to customize the registrar class.'
grep -Fq "return '${registry}';" "$main_file" \
  || die 'Failed to customize the registrar settings key.'
php -l "$main_file" >/dev/null || die 'Customized registrar PHP is invalid.'

info 'Installing module-local Tembo dependencies...'
# A fixed vendor location prevents inherited Composer configuration from selecting
# a global vendor directory. No PNLCS root Composer files are read or changed.
COMPOSER=composer.json COMPOSER_VENDOR_DIR=vendor COMPOSER_ALLOW_SUPERUSER=1 \
  composer install --working-dir="$module_dir/namingo" \
  --no-dev --prefer-dist --no-interaction --no-progress --no-plugins --no-scripts --optimize-autoloader
[[ -f "$module_dir/namingo/vendor/autoload.php" ]] || die 'Module-local Tembo installation failed.'
php -r 'require $argv[1]; \Pinga\Tembo\EppRegistryFactory::create("generic");' \
  "$module_dir/namingo/vendor/autoload.php" || die 'Module-local Tembo cannot be loaded.'

# Preserve existing TLS credentials during upgrades. PNLCS stores registrar settings
# in the database, so module code itself can be replaced cleanly.
credential_backup="$workdir/credential-backup"
mkdir -p "$credential_backup"
if [[ -d "$module_dest" ]]; then
  # Preserve nested credentials too; the draft also allowed namingo/cert.pem.
  while IFS= read -r -d '' credential; do
    relative=${credential#"$module_dest/"}
    "${SUDO[@]}" mkdir -p -- "$credential_backup/$(dirname "$relative")"
    "${SUDO[@]}" cp -a -- "$credential" "$credential_backup/$relative"
  done < <("${SUDO[@]}" find "$module_dest" -path "$module_dest/namingo/vendor" -prune -o \
    \( -type f -o -type l \) \( -name '*.pem' -o -name '*.key' -o -name '*.crt' -o -name '*.cer' \) -print0)
fi

stage_dest="$registrars_dir/.${registry}.install.$$"
backup_dest="$registrars_dir/.${registry}.backup.$$"
[[ ! -e "$stage_dest" && ! -L "$stage_dest" && ! -e "$backup_dest" && ! -L "$backup_dest" ]] \
  || die 'Installation staging/backup path already exists. Retry after checking those paths.'
"${SUDO[@]}" mkdir -p -- "$stage_dest"
stage_owned='yes'
"${SUDO[@]}" cp -a -- "$module_dir/." "$stage_dest/"

# Conservative production permissions: executable directories, non-executable module files.
"${SUDO[@]}" find "$stage_dest" -type d -exec chmod 0755 {} +
"${SUDO[@]}" find "$stage_dest" -type f -exec chmod 0644 {} +
"${SUDO[@]}" cp -a -- "$credential_backup/." "$stage_dest/"

"${SUDO[@]}" chown -R www-data:www-data -- "$stage_dest"
"${SUDO[@]}" find "$stage_dest" -path "$stage_dest/namingo/vendor" -prune -o \
  -type f \( -name '*.pem' -o -name '*.key' -o -name '*.crt' -o -name '*.cer' \) -exec chmod 0600 {} +

if [[ -e "$module_dest" ]]; then
  "${SUDO[@]}" mv -- "$module_dest" "$backup_dest"
  if ! "${SUDO[@]}" mv -- "$stage_dest" "$module_dest"; then
    "${SUDO[@]}" mv -- "$backup_dest" "$module_dest" || true
    die 'Failed to install customized module; previous module was restored.'
  fi
  "${SUDO[@]}" rm -rf -- "$backup_dest"
  info "Upgraded PNLCS registrar module: $registry"
else
  "${SUDO[@]}" mv -- "$stage_dest" "$module_dest"
  info "Installed PNLCS registrar module: $registry"
fi

cert_path="$module_dest/${registry}_cert.pem"
key_path="$module_dest/${registry}_key.pem"
cert_generated='no'

if ask_yes_no "Generate a self-signed TEST EPP certificate for ${registry_name}?"; then
  need_cmd openssl

  if [[ -e "$cert_path" || -e "$key_path" ]]; then
    die "Refusing to overwrite an existing certificate/key: $cert_path or $key_path"
  fi

  cert_tmp="$workdir/${registry}_cert.pem"
  key_tmp="$workdir/${registry}_key.pem"

  openssl genrsa -out "$key_tmp" 2048 >/dev/null 2>&1
  openssl req -new -x509 \
    -key "$key_tmp" \
    -out "$cert_tmp" \
    -days 365 \
    -sha256 \
    -subj "/C=XX/ST=Test/L=Test/O=Namingo Test/OU=EPP/CN=${registry}.test/emailAddress=test@example.invalid" \
    >/dev/null 2>&1

  "${SUDO[@]}" install -o www-data -g www-data -m 0600 -- "$cert_tmp" "$cert_path"
  "${SUDO[@]}" install -o www-data -g www-data -m 0600 -- "$key_tmp" "$key_path"
  cert_generated='yes'
fi

cat <<EOF_DONE

Installation complete.
Registry module: ${registry_name}
PNLCS path: ${pnlcs_path}
Module directory: ${module_dest}
Main module file: ${module_dest}/${registry_name}Registrar.php
Registrar key: ${registry}
EOF_DONE

if [[ "$cert_generated" == 'yes' ]]; then
  cat <<EOF_CERT
Test certificate: ${cert_path}
Test private key: ${key_path}

These are self-signed TEST credentials only. Replace them with the certificate/key accepted by the registry before production EPP use.
EOF_CERT
else
  info 'Test certificate: not generated.'
fi

cat <<EOF_FIELDS

Enable ${registry} in PNLCS under Configuration -> Domain Registrars, then assign
it under Configuration -> Domain Pricing. Select the Registry Profile and other
options required by your registry; the installer name identifies the module,
not Tembo's registry protocol profile.

After installation or upgrade, clear cached application data and restart workers:
  cd "${pnlcs_path}"
  php artisan optimize:clear
  php artisan queue:restart
EOF_FIELDS
