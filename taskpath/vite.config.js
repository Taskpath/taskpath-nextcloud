import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	main: 'src/main.js',
}, {
	// js/ also holds the hand-written settings and sidebar scripts.
	emptyOutputDirectory: false,
	inlineCSS: { relativeCSSInjection: true },
})
