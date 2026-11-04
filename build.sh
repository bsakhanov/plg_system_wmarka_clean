#!/usr/bin/env bash
# Builds dist/: plg_system_wmarka_clean_v<version>.zip, SHA256SUMS.txt and updates.xml (written into the repository by tools/release.sh).
set -euo pipefail
cd "$(dirname "$0")"
NAME="Webmarka Clean"
EL=plg_system_wmarka_clean
REPO=bsakhanov/plg_system_wmarka_clean
VER=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' wmarka_clean.xml | head -1)
if command -v php >/dev/null 2>&1; then
    find src services script.php -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
fi
rm -rf build dist && mkdir -p "build/$EL" dist
cp -r wmarka_clean.xml script.php services src language media "build/$EL/"
(cd "build/$EL" && zip -qrX "../../dist/${EL}_v${VER}.zip" .)
SHA=$(sha256sum "dist/${EL}_v${VER}.zip" | cut -d' ' -f1)
(cd dist && sha256sum ./*.zip > SHA256SUMS.txt)
cat > dist/updates.xml <<XML
<?xml version="1.0" encoding="utf-8"?>
<!-- Update server of $NAME. Written by tools/release.sh. -->
<updates>
    <update>
        <name>$NAME</name>
        <description>$NAME $VER</description>
        <element>wmarka_clean</element>
        <type>plugin</type>
        <folder>system</folder>
        <client>site</client>
        <version>$VER</version>
        <infourl title="$NAME $VER">https://github.com/$REPO/releases/tag/v$VER</infourl>
        <downloads>
            <downloadurl type="full" format="zip">https://github.com/$REPO/releases/download/v$VER/${EL}_v${VER}.zip</downloadurl>
        </downloads>
        <sha256>$SHA</sha256>
        <changelogurl>https://raw.githubusercontent.com/$REPO/main/changelog.xml</changelogurl>
        <maintainer>Beibit Sakhanov</maintainer>
        <maintainerurl>https://webmarka.kz</maintainerurl>
        <targetplatform name="joomla" version="6\.[0-9]+"/>
        <php_minimum>8.2</php_minimum>
    </update>
</updates>
XML
echo "Built $NAME $VER:"; ls -l dist
