all: core editor

core:
	wasm-pack build core --target web --out-dir assets/core --out-name melintir-core

editor:
	npm --prefix editor install
	npm --prefix editor run build

link:
	ln -sfn $(PWD) /home/sony/projects/sony/wordpress/wp-content/plugins/melintir

check:
	php -l melintir.php
	php -l includes/Plugin.php
	php -l includes/Renderer.php
	php -l includes/Rest.php
	php -l includes/Security.php
	php -l includes/Form.php
	php -l includes/Theme.php
	php -l includes/Migrator.php
	php -l includes/Blocks.php
	node --check assets/blocks/template.js
	cargo test --manifest-path core/Cargo.toml
