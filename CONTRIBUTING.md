# Contributing to AviationWX.org

Thank you for your interest in contributing to AviationWX.org! This document provides guidelines and instructions for contributing.

## Getting Started

1. **Fork the repository** on GitHub
2. **Clone your fork** locally:
   ```bash
   git clone https://github.com/YOUR_USERNAME/aviationwx.org.git
   cd aviationwx.org
   ```
3. **Set up local development** - See [LOCAL_SETUP.md](LOCAL_SETUP.md) for detailed instructions

## Development Setup

See [LOCAL_SETUP.md](LOCAL_SETUP.md) for complete local development instructions. Quick start:

```bash
# Copy configuration template
cp airports.json.example airports.json

# Edit with test credentials (never commit real credentials)
# Then start Docker
make up
```

## How to Contribute

### Reporting Bugs

1. **Check existing issues** to see if the bug is already reported
2. **Create a new issue** with:
   - Clear title and description
   - Steps to reproduce
   - Expected vs actual behavior
   - Environment details (PHP version, Docker version, etc.)
   - Error messages or logs (without sensitive data)

### Suggesting Features

1. **Check existing issues** for similar suggestions
2. **Create a feature request** with:
   - Use case and motivation
   - Proposed solution or implementation ideas
   - Any related issues

### Code Contributions

1. **Create a branch** for your changes:
   ```bash
   git checkout -b feature/your-feature-name
   # or
   git checkout -b fix/your-bug-fix
   ```

2. **Make your changes** following our coding standards:
   - Follow existing code style and conventions
   - Add comments for complex logic
   - Update documentation for user-facing changes
   - Write clear commit messages

3. **Test your changes**:
   ```bash
   # Run tests if available
   make test
   
   # Test manually
   make up
   # Visit http://localhost:8080/?airport=kspb
   ```

4. **Commit your changes**:
   ```bash
   git add .
   git commit -m "Description of your changes"
   ```

5. **Push and create a Pull Request**:
   ```bash
   git push origin feature/your-feature-name
   ```
   Then create a PR on GitHub with:
   - Clear title and description
   - Reference related issues
   - Screenshots if UI changes
   - Testing notes

## Coding Standards

### PHP Code Style

- Use PSR-12 coding standards where applicable
- Add docblocks for functions and classes:
  ```php
  /**
   * Brief description
   * 
   * @param type $param Description
   * @return type Description
   */
  ```
- Use meaningful variable and function names
- Comment complex logic
- Keep functions focused and single-purpose

### Security Guidelines

- **Never commit sensitive data** (API keys, passwords, credentials)
- Use `airports.json.example` as a template
- Validate and sanitize all user input
- Follow security best practices in [SECURITY.md](SECURITY.md)

### Documentation

- Update relevant documentation files for user-facing changes
- Add inline comments for complex logic
- Update README.md if adding new features
- Keep code examples in documentation accurate

## Pull Request Process

1. **Ensure your code works** and doesn't break existing functionality
2. **Update documentation** for any changes that affect users or developers
3. **Keep commits focused** - one logical change per commit
4. **Write clear commit messages**:
   ```
   Short summary (50 chars or less)
   
   More detailed explanation if needed. Wrap at 72 characters.
   Explain what and why vs. how.
   ```

5. **Respond to feedback** promptly and professionally
6. **Wait for review** before merging (even if you have write access)

## Areas for Contribution

### Code Improvements

- **Performance optimization**: Caching, query optimization
- **Error handling**: Better error messages and logging
- **Code quality**: Refactoring, removing duplication
- **Testing**: Unit tests, integration tests

### Documentation

- **User documentation**: Clearer setup instructions
- **API documentation**: Better endpoint documentation
- **Code comments**: Clarifying complex functions
- **Examples**: More configuration examples

### Features

- **New weather sources**: Additional weather API integrations
- **UI/UX improvements**: Better mobile experience
- **Accessibility**: WCAG compliance improvements
- **Internationalization**: Multi-language support

## Questions?

- Open an issue for questions or discussions
- Check existing documentation first
- Be respectful and constructive in all communications

## Code of Conduct

- Be respectful and inclusive
- Welcome newcomers and help them learn
- Focus on constructive feedback
- Keep discussions professional

Thank you for contributing to AviationWX.org! 🛩️

