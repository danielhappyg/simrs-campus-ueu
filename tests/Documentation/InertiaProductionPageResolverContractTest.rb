# frozen_string_literal: true

require 'minitest/autorun'

class InertiaProductionPageResolverContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  APP = File.join(ROOT, 'resources/js/app.tsx')

  def test_production_page_resolver_excludes_test_modules
    source = File.read(APP, encoding: Encoding::UTF_8)

    assert_includes source, "import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';"
    assert_includes source, "'./pages/**/*.tsx'"
    assert_includes source, "'!./pages/**/*.test.tsx'"
    assert_match(
      %r{resolvePageComponent<PageModule>\(\s*`\./pages/\$\{name\}\.tsx`,\s*pageModules,?\s*\)}m,
      source
    )
  end

  def test_current_build_manifest_contains_no_test_page_entry
    manifest_path = File.join(ROOT, 'public/build/manifest.json')
    skip 'Production build manifest is not present.' unless File.file?(manifest_path)

    manifest = File.read(manifest_path, encoding: Encoding::UTF_8)
    refute_match(%r{resources/js/pages/.+\.test\.tsx}, manifest)
  end
end
