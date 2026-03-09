# Contributing to Zabbix FinOps Toolkit

Thank you for considering contributing! Every contribution helps make this tool more useful for the community.

## How to Contribute

### Reporting Bugs

1. Check [existing issues](https://github.com/Lfijho/ZabbixFinOpsToolkit/issues) first
2. Open a new issue with:
   - Zabbix version
   - PHP version
   - Steps to reproduce
   - Expected vs actual behavior
   - Error logs (if any) from `Administration → Audit log` or Zabbix frontend logs

### Suggesting Features

Open an issue with the `enhancement` label describing:
- The problem you're trying to solve
- Your proposed solution
- Any alternatives you considered

### Submitting Code

1. **Fork** the repository
2. **Create a branch**: `git checkout -b feature/my-feature`
3. **Follow the code style**:
   - Match existing Zabbix module conventions
   - Use `declare(strict_types = 0)` as per Zabbix standards
   - Use `_('string')` for all user-facing text (i18n ready)
   - Prefix CSS classes with `finops-`
4. **Test your changes** in a working Zabbix 7.x environment
5. **Commit** with a clear message: `git commit -m "Add: description of change"`
6. **Push** and open a **Pull Request**

### Commit Message Format

```
Type: Short description

Longer explanation if needed.
```

Types: `Add`, `Fix`, `Change`, `Remove`, `Docs`

## Development Setup

1. Install Zabbix 7.0+ with the frontend
2. Clone this repo into `/usr/share/zabbix/ui/modules/ZabbixFinOpsToolkit/`
3. Enable the module in **Administration → General → Modules**
4. Make changes and refresh the page to test

### Key Files

| File | Purpose |
|------|---------|
| `actions/CostAnalyzer.php` | Main business logic — metric queries, score calculation, recommendations |
| `actions/CostAnalyzerCsvExport.php` | CSV export (mirrors main logic) |
| `views/finops.costanalyzer.view.php` | HTML output — table, filters, badges |
| `views/finops.costanalyzer.csv.php` | CSV output template |
| `Module.php` | Menu registration |
| `manifest.json` | Module metadata and route definitions |

### Adding New Metrics

To add a new metric (e.g., swap usage):

1. Add the item key constant in `CostAnalyzer.php`
2. Include it in the API Item search array
3. Add the classification logic in the item loop
4. Query trends in the processing loop
5. Add the column to the view and CSV template
6. Update the recommendation logic if the metric should be a safeguard

## Code of Conduct

- Be respectful and constructive
- Focus on the technical merits of contributions
- Help newcomers get started

## Questions?

Open an issue with the `question` label.
