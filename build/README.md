<!---
(c) Sergei Shilko <contact@sshilko.com>

MIT License

For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.

@license https://opensource.org/licenses/mit-license.php MIT
-->
#### Code quality configuration

- [Pre-commit](https://pre-commit.com/)
- [PHPCS CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer)
- [PHPCS CodeBeautifier](https://github.com/squizlabs/PHP_CodeSniffer)
- [PHP MessDetector](https://phpmd.org/)
- [PHP Psalm](https://psalm.dev/)
- [phpDocumentor](https://www.phpdoc.org)
- [phan/phan](https://github.com/phan/phan)
- [PHPCPD](https://github.com/sebastianbergmann/phpcpd)

#### Artifacts and other metadata expects few github branches to exist

- badges
- pages

Use following commands to pre-create branches
```
git switch --orphan <branch>
git commit --allow-empty -m "Initial commit on empty branch"
git push -u origin <branch>
```