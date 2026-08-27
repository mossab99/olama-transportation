<?php

class Olama_Transportation_I18n_Test extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        delete_option('olama_transportation_settings');
        parent::tearDown();
    }

    public function test_english_is_the_safe_default()
    {
        delete_option('olama_transportation_settings');
        $this->assertSame('en', Olama_Transportation_I18n::language());
        $this->assertSame('ltr', Olama_Transportation_I18n::direction());
        $this->assertSame('Transportation Settings', Olama_Transportation_I18n::translate('Transportation Settings'));
    }

    public function test_arabic_setting_controls_translation_and_direction()
    {
        update_option('olama_transportation_settings', array('language' => 'ar'));
        $this->assertSame('ar', Olama_Transportation_I18n::language());
        $this->assertSame('rtl', Olama_Transportation_I18n::direction());
        $this->assertSame('إعدادات المواصلات', Olama_Transportation_I18n::translate('Transportation Settings'));
        $this->assertSame('تقرير المواصلات', Olama_Transportation_I18n::translate('Transportation report'));
        $this->assertSame('البحث باسم الرحلة', Olama_Transportation_I18n::translate('Search trip name'));
    }

    public function test_invalid_language_falls_back_to_english()
    {
        update_option('olama_transportation_settings', array('language' => 'invalid'));
        $this->assertSame('en', Olama_Transportation_I18n::language());
        $this->assertSame('ltr', Olama_Transportation_I18n::direction());
    }

    public function test_gettext_filter_only_changes_the_transportation_domain()
    {
        update_option('olama_transportation_settings', array('language' => 'ar'));
        $this->assertSame('المواصلات', Olama_Transportation_I18n::filter_gettext('Transportation', 'Transportation', 'olama-transportation'));
        $this->assertSame('Another translation', Olama_Transportation_I18n::filter_gettext('Another translation', 'Transportation', 'another-domain'));
    }

    public function test_settings_endpoint_persists_a_valid_language()
    {
        update_option('olama_transportation_settings', array('optimizer_provider' => 'manual'));
        $request = new WP_REST_Request('PUT', '/olama-transportation/v1/settings');
        $request->set_body_params(array('language' => 'ar', 'optimizer_provider' => 'manual'));
        (new Olama_Transportation_REST())->save_settings($request);
        $settings = get_option('olama_transportation_settings');
        $this->assertSame('ar', $settings['language']);
    }
}
