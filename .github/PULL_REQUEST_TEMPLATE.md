# Pull Request

## Description
Brief description of changes

## Type of Change
- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Documentation update
- [ ] Test update

## Testing
- [ ] All existing tests pass (`make test` or `composer test`)
- [ ] New tests added for changes (if applicable)
- [ ] Manual testing completed
- [ ] Tested in local Docker environment

## Critical Safety Checklist
⚠️ **These must be verified for any changes to weather data handling:**

- [ ] Stale data safety checks still work (3-hour threshold)
- [ ] Flight category calculations are correct
- [ ] Daily tracking values (high/low temps, peak gust) are preserved
- [ ] Error handling doesn't expose sensitive information
- [ ] Input validation prevents invalid airport IDs
- [ ] Rate limiting is still enforced

## Changes to Critical Files
If you modified any of these files, please explain:
- `weather.php` - Core weather data processing
- `config-utils.php` - Airport configuration validation
- `rate-limit.php` - Rate limiting
- `calculateFlightCategory()` - Flight safety calculations
- `nullStaleFieldsBySource()` - Safety data expiration

## Breaking Changes
- [ ] This PR introduces breaking changes
- [ ] Breaking changes are documented
- [ ] Migration path provided (if applicable)

## Checklist
- [ ] Code follows project coding standards
- [ ] Self-review completed
- [ ] Comments added for complex code
- [ ] Documentation updated (if needed)
- [ ] No warnings or errors from linters
- [ ] All tests pass locally
- [ ] PR description is clear and complete

## Related Issues
Fixes #(issue number)

## Additional Notes
Any additional information that reviewers should know

