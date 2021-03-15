import { registerPlugin } from '@wordpress/plugins';
import { Button } from '@wordpress/components';
import { CheckboxControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';

const TranslateButton = () => <Button isSecondary>Send to translation</Button>;

// component for the various languages
const LanguageCheckboxControl = ({language}) => {
	const [ isChecked, setChecked ] = useState( true );
	return (
		<CheckboxControl
			label={language}
			name="language[]"
			value={language.toLowerCase()}
			checked={ isChecked }
			onChange={ setChecked }
		/>
	)
};

const PluginDocumentSettingPanelBts = () => {
	return (
		<PluginDocumentSettingPanel
			name="bts-panel"
			title="Translations"
			className="bts-panel"
		>
			<p>Translate your document into more languages</p>
			<div className="languages" />
			<LanguageCheckboxControl language="Danish" />
			<LanguageCheckboxControl language="Norwegian" />
			<LanguageCheckboxControl language="Swedish" />
			<LanguageCheckboxControl language="Finnish" />
			<br/>
			<TranslateButton />

		</PluginDocumentSettingPanel>
	)
};


registerPlugin('plugin-document-setting-panel-bts', {
	render: PluginDocumentSettingPanelBts,
	icon: '',
});

