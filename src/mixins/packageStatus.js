import { mapState, mapGetters } from 'vuex';

import metadata from 'contao-package-list/src/mixins/metadata';

export default {
    mixins: [metadata],

    computed: {
        ...mapState('packages', ['required', 'add', 'change']),
        ...mapGetters('packages', [
            'installed',
            'hasRoot',
            'packageInstalled',
            'packageRoot',
            'packageRequired',
            'packageMissing',
            'packageAdded',
            'packageUpdated',
            'packageChanged',
            'packageRemoved',
            'packageFeature',
            'packageVisible',
            'packageSuggested',
            'packageConstraintAdded',
            'packageConstraintChanged',
            'packageConstraintInstalled',
            'packageConstraintRequired',
            'contaoSupported',
        ]),

        isInstalled: (vm) => vm.packageInstalled(vm.data.name),
        isRootInstalled: (vm) => vm.isInstalled && vm.packageRoot(vm.data.name),
        isRequired: (vm) => vm.packageRequired(vm.data.name),
        isAdded: (vm) => vm.packageAdded(vm.data.name),
        isMissing: (vm) => vm.packageMissing(vm.data.name),
        isChanged: (vm) => vm.packageChanged(vm.data.name),
        isUpdated: (vm) => vm.packageUpdated(vm.data.name),
        willBeRemoved: (vm) => vm.packageRemoved(vm.data.name),
        willBeInstalled: (vm) => vm.packageAdded(vm.data.name),
        isModified: (vm) => vm.isUpdated || vm.isChanged || vm.willBeRemoved || vm.willBeInstalled,
        isSuggested: (vm) => vm.packageSuggested(vm.data.name),

        isPrivate: (vm) => vm.metadata && !!vm.metadata.private,
        isDependency: (vm) => vm.metadata && !!vm.metadata.dependency,
        isFeature: (vm) => vm.packageFeature(vm.data.name),
        isVisible: (vm) => vm.packageVisible(vm.data.name),
        isTheme: (vm) => vm.data.type === 'contao-theme' || (vm.metadata && vm.metadata.type === 'contao-theme'),
        isContao: (vm) => vm.data.name === 'contao/manager-bundle',

        isUpload: (vm) => {
            return (
                vm.metadata &&
                vm.metadata['installation-source'] === 'dist' &&
                vm.metadata.dist &&
                new RegExp('/contao-manager/packages/[^/]+.zip$', 'i').test(vm.metadata.dist.url)
            );
        },

        installedVersion: (vm) => (vm.installed[vm.data.name] ? vm.installed[vm.data.name].version : null),
        installedTime: (vm) => (vm.installed[vm.data.name] ? vm.installed[vm.data.name].time : null),

        isCompatible: (vm) => vm.contaoSupported(vm.metadata.contaoConstraint),
        canBeInstalled: (vm) => (!vm.isPrivate || vm.isSuggested) && !vm.isTheme && (!vm.isDependency || vm.isSuggested) && vm.isCompatible,

        constraintInstalled: (vm) => vm.packageConstraintInstalled(vm.data.name),
        constraintRequired: (vm) => vm.packageConstraintRequired(vm.data.name),
        constraintAdded: (vm) => vm.packageConstraintAdded(vm.data.name),
        constraintChanged: (vm) => vm.packageConstraintChanged(vm.data.name),

        targetConstraint: (vm) => vm.$store.state.packages?.change[vm.data.name] || vm.$store.state.packages?.root?.require[vm.data.name],
    },

    methods: {
        install() {
            this.$store.commit('packages/add', this.metadata || this.data);
        },

        update() {
            this.$store.commit('packages/update', this.data.name);
        },

        uninstall() {
            if (this.willBeInstalled && !this.isInstalled) {
                this.$store.commit('packages/restore', this.data.name);
            } else {
                this.$store.commit('packages/restore', this.data.name);
                this.$store.dispatch('packages/uploads/unconfirm', this.data.name);
                this.$store.commit('packages/remove', this.data.name);
            }
        },
    },

    watch: {
        targetConstraint() {
            this.$store.commit('algolia/uncache', this.data.name);
            this.loadMetadata();
        },
    },
};
