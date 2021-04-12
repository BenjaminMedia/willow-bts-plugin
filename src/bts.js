import { registerPlugin } from '@wordpress/plugins';
import { Button } from '@wordpress/components';
import { CheckboxControl } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';

const TranslateButton = () => <Button isSecondary>Send to translation</Button>;

// component for the various languages
const LanguageCheckboxControl = ({language, code}) => {
	const [ isChecked, setChecked ] = useState( true );
	return (
		<CheckboxControl
			label={language}
			name="language[]"
			value={code}
		/>
	)
};

const PluginDocumentSettingPanelBts = () => {
	const [translations, setTranslations] = useState([]);
	useEffect(() => {
		async function initPlugin() {
			let post = wp.data.select('core/editor').getCurrentPost();
			// fetches information about the current post
			const response = await fetch('/wp-json/bonnier-willow-bts/v1/articles/'+post.id);
			if (! response.ok) {
				return;
			}

			// fetching the list of available languages
			const json = await response.json();
			console.log(json);
			setTranslations(json.languages);
		}
		// starting the script
		initPlugin();
	}, []);
	return (
		<PluginDocumentSettingPanel
			name="bts-panel"
			title="Translations"
			className="bts-panel"
		>
			<p>Translate your document into more languages</p>
			<div className="languages" />
			{translations.map((translation, index) => (
				<LanguageCheckboxControl language={translation.name} code={translation.code} state={translation.state} />
			))}
			<br/>
			<TranslateButton />

		</PluginDocumentSettingPanel>
	)
};


registerPlugin('plugin-document-setting-panel-bts', {
	render: PluginDocumentSettingPanelBts,
	icon: '',
});

